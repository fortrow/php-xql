<?php

namespace XQL\Dev\Monitor;

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use Throwable;
use XQL\Core\Utils\Env;

class DaemonMonitor
{
    private XQLLogger $logger;
    private string $statePath;

    public function __construct(?XQLLogger $logger = null)
    {
        $this->logger = $logger ?: new XQLLogger();
        $this->statePath = Env::get("XQL_DAEMON_ALERT_STATE_PATH") ?: dirname($this->logger->path()) . "/alert-state.json";
    }

    public function started(array $context = []): void
    {
        $this->logger->info("XQL daemon started.", $context + ['log_path' => $this->logger->path()]);
        $this->registerShutdownHandler($context);
    }

    public function stopped(array $summary = []): void
    {
        $this->logger->info("XQL daemon stopped.", $summary);
    }

    public function fault(Throwable $throwable, array $context = []): void
    {
        $payload = $context + [
            'exception' => get_class($throwable),
            'message' => $throwable->getMessage(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'log_path' => $this->logger->path(),
        ];

        $this->logger->error("XQL daemon fault.", $payload);
        $this->alert("fault", "Fortrow XQL daemon fault", $this->formatBody("The XQL daemon faulted.", $payload), true);
    }

    public function health(array $summary = []): void
    {
        $metrics = $this->metrics();
        $context = $summary + $metrics + ['log_path' => $this->logger->path()];
        $this->logger->info("XQL daemon health.", $context);

        $heartbeatMinutes = (int) (Env::get("XQL_DAEMON_HEARTBEAT_MINUTES") ?: 0);
        if($heartbeatMinutes > 0) {
            $this->alert("heartbeat", "Fortrow XQL daemon heartbeat", $this->formatBody("The XQL daemon is running.", $context), false, $heartbeatMinutes * 60);
        }

        $loadThreshold = (float) (Env::get("XQL_DAEMON_LOAD_ALERT_THRESHOLD") ?: 0);
        if($loadThreshold > 0 && ($metrics['load_1m'] ?? 0) >= $loadThreshold) {
            $this->logger->warning("XQL daemon high load.", $context);
            $this->alert("high_load", "Fortrow XQL daemon high load", $this->formatBody("The XQL daemon host is under high load.", $context), false);
        }
    }

    public function logger(): XQLLogger
    {
        return $this->logger;
    }

    public function status(): array
    {
        return [
            'log_path' => $this->logger->path(),
            'alert_state_path' => $this->statePath,
            'alert_emails' => $this->recipients(),
            'alert_cooldown_seconds' => (int) (Env::get("XQL_DAEMON_ALERT_COOLDOWN_SECONDS") ?: 900),
            'heartbeat_minutes' => (int) (Env::get("XQL_DAEMON_HEARTBEAT_MINUTES") ?: 0),
            'load_alert_threshold' => (float) (Env::get("XQL_DAEMON_LOAD_ALERT_THRESHOLD") ?: 0),
            'metrics' => $this->metrics(),
        ];
    }

    private function metrics(): array
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [null, null, null];

        return [
            'pid' => getmypid(),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'load_1m' => $load[0],
            'load_5m' => $load[1],
            'load_15m' => $load[2],
        ];
    }

    private function registerShutdownHandler(array $context = []): void
    {
        static $registered = false;
        if($registered) return;
        $registered = true;

        register_shutdown_function(function () use ($context) {
            $error = error_get_last();
            if(!$error) return;
            if(!in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;

            $payload = $context + [
                'error_type' => $error['type'],
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
                'log_path' => $this->logger->path(),
            ];

            $this->logger->error("XQL daemon fatal shutdown.", $payload);
            $this->alert("fatal_shutdown", "Fortrow XQL daemon fatal shutdown", $this->formatBody("The XQL daemon stopped because of a fatal PHP error.", $payload), true);
        });
    }

    private function alert(string $key, string $subject, string $body, bool $urgent = false, ?int $cooldownSeconds = null): void
    {
        if(!$urgent && !$this->shouldSend($key, $cooldownSeconds)) return;

        $recipients = $this->recipients();
        if(count($recipients) === 0) {
            $this->logger->warning("XQL alert suppressed because no recipients are configured.", ['alert' => $key]);
            return;
        }

        try {
            $mailer = new Mailer(Transport::fromDsn($this->dsn()));
            $email = (new Email())
                ->from($this->fromAddress())
                ->subject($subject)
                ->text($body);

            foreach($recipients as $recipient) {
                $email->addTo($recipient);
            }

            $mailer->send($email);
            $this->markSent($key);
            $this->logger->info("XQL alert email sent.", ['alert' => $key, 'recipients' => $recipients]);
        } catch(Throwable $e) {
            $this->logger->error("XQL alert email failed.", [
                'alert' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function shouldSend(string $key, ?int $cooldownSeconds = null): bool
    {
        $cooldownSeconds = $cooldownSeconds ?? (int) (Env::get("XQL_DAEMON_ALERT_COOLDOWN_SECONDS") ?: 900);
        $state = $this->state();
        $last = (int) ($state[$key] ?? 0);
        return time() - $last >= $cooldownSeconds;
    }

    private function markSent(string $key): void
    {
        $state = $this->state();
        $state[$key] = time();
        file_put_contents($this->statePath, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function state(): array
    {
        if(!is_file($this->statePath)) return [];
        $decoded = json_decode((string) file_get_contents($this->statePath), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function recipients(): array
    {
        $raw = Env::get("XQL_DAEMON_ALERT_EMAILS") ?: Env::get("MAIL_FROM_ADDRESS");
        return array_values(array_filter(array_map('trim', explode(',', (string) $raw))));
    }

    private function fromAddress(): string
    {
        return Env::get("MAIL_FROM_ADDRESS") ?: "xql@fortrow.com";
    }

    private function dsn(): string
    {
        $mailer = Env::get("MAIL_MAILER") ?: "smtp";
        if($mailer === "log" || $mailer === "array") {
            return "null://null";
        }

        $host = Env::get("MAIL_HOST") ?: "localhost";
        $port = Env::get("MAIL_PORT") ?: "25";
        $username = Env::get("MAIL_USERNAME");
        $password = Env::get("MAIL_PASSWORD");
        $encryption = Env::get("MAIL_ENCRYPTION");
        $scheme = $encryption === "ssl" ? "smtps" : "smtp";
        $auth = ($username && $password) ? rawurlencode($username) . ":" . rawurlencode($password) . "@" : "";
        $query = ($encryption === "tls") ? "?encryption=tls" : "";

        return $scheme . "://" . $auth . $host . ":" . $port . $query;
    }

    private function formatBody(string $headline, array $context): string
    {
        return $headline . "\n\n"
            . "Environment: " . (Env::get("APP_ENV") ?: "unknown") . "\n"
            . "Application: " . (Env::get("APP_URL") ?: "unknown") . "\n"
            . "Log path: " . ($context['log_path'] ?? $this->logger->path()) . "\n\n"
            . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
