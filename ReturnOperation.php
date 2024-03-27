<?php

namespace Test;

use Exception;
use Throwable;

class ReturnOperation extends ReferencesOperation
{

    private array $data;
    public const TYPE_NEW    = 1;
    public const TYPE_CHANGE = 2;

    public function __construct()
    {
        $this->data = $this->getRequest('data');
    }

    /**
     * @throws Exception
     */
    public function doOperation(): array
    {
        $result = [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail'   => false,
            'notificationClientBySms'     => [
                'isSent'  => false,
                'message' => '',
            ],
        ];

        $resellerId = $this->getResellerId();
        $notificationType = $this->getNotificationType();
        $client = $this->findClient($resellerId);
        $fullName = $client->getFullName();

        if (empty($fullName)) {
            $fullName = $client->name;
        }

        $creator = $this->findEmployee($this->data['creatorId']);
        $expert = $this->findEmployee($this->data['expertId']);
        $differences = $this->calculateDifferences($resellerId, $notificationType);
        $templateData = $this->buildData($creator, $expert, $fullName, $differences);
        $this->validateData($templateData);

        $emailFrom = $this->getResellerEmailFrom($resellerId);
        $this->sendEmployeeNotifications($emailFrom, $templateData, $resellerId);

        if ($notificationType === self::TYPE_CHANGE && !empty($this->data['differences']['to'])) {
            $this->sendClientNotifications($result, $emailFrom, $templateData, $resellerId, $client, $this->data);
        }

        return $result;
    }

    private function getResellerId(): int
    {
        if (!isset($this->data['resellerId'])) {
            throw new Exception('Empty resellerId', 400);
        }

        return $this->data['resellerId'];
    }

    private function getNotificationType(): int
    {
        if (!isset($this->data['notificationType'])) {
            throw new Exception('Empty notificationType', 400);
        }

        return $this->data['notificationType'];
    }

    private function findClient(int $resellerId): Contractor
    {
        $client = Contractor::getById($this->data['clientId']);

        if ($client->type !== Contractor::TYPE_CUSTOMER || $client->seller->id !== $resellerId) {
            throw new Exception('Client not found or invalid!', 400);
        }

        return $client;
    }

    private function findEmployee(int $employeeId): Employee
    {
        $employee = Employee::getById($employeeId);

        if ($employee === null) {
            throw new Exception('Employee not found!', 400);
        }

        return $employee;
    }

    /**
     * @throws Exception
     */
    private function calculateDifferences(int $resellerId, int $notificationType): string
    {
        $differences = '';

        if ($notificationType === self::TYPE_NEW) {
            $differences = __('NewPositionAdded', null, $resellerId);
        } elseif ($notificationType === self::TYPE_CHANGE && !empty($this->data['differences'])) {
            $differences = __('PositionStatusHasChanged', [
                'FROM' => Status::getStatus((int)$this->data['differences']['from']),
                'TO'   => Status::getStatus((int)$this->data['differences']['to']),
            ], $resellerId);
        }

        return $differences;
    }

    private function buildData($cr, $et, string $cFullName, string $differences): array
    {
        return [
            'COMPLAINT_ID'       => (int)$this->data['complaintId'],
            'COMPLAINT_NUMBER'   => (string)$this->data['complaintNumber'],
            'CREATOR_ID'         => (int)$this->data['creatorId'],
            'CREATOR_NAME'       => $cr->getFullName(),
            'EXPERT_ID'          => (int)$this->data['expertId'],
            'EXPERT_NAME'        => $et->getFullName(),
            'CLIENT_ID'          => (int)$this->data['clientId'],
            'CLIENT_NAME'        => $cFullName,
            'CONSUMPTION_ID'     => (int)$this->data['consumptionId'],
            'CONSUMPTION_NUMBER' => (string)$this->data['consumptionNumber'],
            'AGREEMENT_NUMBER'   => (string)$this->data['agreementNumber'],
            'DATE'               => (string)$this->data['date'],
            'DIFFERENCES'        => $differences,
        ];
    }

    /**
     * @throws Exception
     */
    private function validateData(array $templateData): void
    {
        foreach ($templateData as $key => $tempData) {
            if (empty($tempData)) {
                throw new Exception(sprintf('Template Data (%s) is empty!', $key), 500);
            }
        }
    }

    private function getResellerEmailFrom(int $resellerId): string
    {
        //mock email
        return 'contractor@example.com';
    }

    private function sendEmployeeNotifications(string $emailFrom, array $templateData, int $resellerId): void
    {
        $emails = $this->getEmailsByPermit($resellerId, 'tsGoodsReturn');

        if (!empty($emailFrom) && count($emails) > 0) {
            foreach ($emails as $email) {
                try {
                    MessagesClient::sendMessage([
                        0 => [
                            'emailFrom' => $emailFrom,
                            'emailTo'   => $email,
                            'subject'   => __('complaintEmployeeEmailSubject', $templateData, $resellerId),
                            'message'   => __('complaintEmployeeEmailBody', $templateData, $resellerId),
                        ],
                    ], $resellerId, NotificationEvents::CHANGE_RETURN_STATUS);
                } catch (\Throwable) {
                    throw new Exception('Error during sending an email to employees');
                }
            }
        }
    }

    private function sendClientNotifications(array &$result, string $emailFrom, array $templateData, int $resellerId, Contractor $client, array $data): void
    {
        $error = null;

        if (!empty($emailFrom) && !empty($client->email)) {
            try {
                MessagesClient::sendMessage([
                    0 => [
                        'emailFrom' => $emailFrom,
                        'emailTo'   => $client->email,
                        'subject'   => __('complaintClientEmailSubject', $templateData, $resellerId),
                        'message'   => __('complaintClientEmailBody', $templateData, $resellerId),
                    ],
                ], $resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to']);
            } catch (\Throwable) {
                throw new Exception('Error during sending an email');
            }
        }

        if (!empty($client->mobile)) {
            try {
                // perhaps in send function $error will be &$error
                $res = NotificationManager::send($resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to'], $templateData, $error);

                if ($res) {
                    $result['notificationClientBySms']['isSent'] = true;
                }

                if (!empty($error)) {
                    $result['notificationClientBySms']['message'] = $error;
                }
            } catch (\Throwable) {
                throw new Exception('Error during sending sms');
            }
        }
    }


    /**
     * @return string[]
     */
    private function getEmailsByPermit(int $resellerId, string $event): array
    {
        // fakes the method
        return ['someemeil@example.com', 'someemeil2@example.com'];
    }
}