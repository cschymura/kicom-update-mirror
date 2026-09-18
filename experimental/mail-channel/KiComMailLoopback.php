<?php
declare(strict_types=1);

final class KiComMailLoopback
{
    private KiComMailTransport $transport;
    private string $identity;

    public function __construct(KiComMailTransport $transport, string $identity)
    {
        if (!filter_var($identity, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('MAIL_IDENTITY_INVALID');
        }
        $this->transport = $transport;
        $this->identity = $identity;
    }

    public function run(int $waitSeconds = 20): array
    {
        $probe = $this->transport->probe();
        if (($probe['ready'] ?? false) !== true) {
            return [
                'ok' => false,
                'phase' => 'probe',
                'probe' => $probe,
                'credential_visible' => false,
            ];
        }

        $nonce = bin2hex(random_bytes(8));
        $sent = $this->transport->sendText(
            $this->identity,
            'KiCom Mail Loopback ' . $nonce,
            "Automatischer KiCom-Verbindungstest.\nNonce: {$nonce}\n",
            ['X-KiCom-Loopback' => $nonce]
        );

        $deadline = time() + max(1, min(60, $waitSeconds));
        $found = false;
        do {
            if ($this->transport->inboxHasMessageId((string)$sent['message_id'])) {
                $found = true;
                break;
            }
            if (time() < $deadline) {
                sleep(2);
            }
        } while (time() < $deadline);

        return [
            'ok' => $found,
            'phase' => $found ? 'complete' : 'delivery-wait',
            'message_id' => $sent['message_id'],
            'smtp_accepted' => true,
            'imap_received' => $found,
            'credential_visible' => false,
            'authority_changed' => false,
        ];
    }
}
