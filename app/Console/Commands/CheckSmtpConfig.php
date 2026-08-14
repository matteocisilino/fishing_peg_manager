<?php

/**
 * Fishing Peg Manager
 * 
 * @author      Matteo
 * @copyright   2026 Matteo
 * @license     GNU Affero General Public License v3 (AGPL-3.0)
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Mail\MailManager;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class CheckSmtpConfig extends Command
{
    protected $signature = 'mail:check-smtp {--mailer=smtp : Mailer da verificare}';

    protected $description = 'Verifica la configurazione SMTP e prova a stabilire una connessione';

    public function handle(MailManager $mailManager): int
    {
        $mailerName = $this->option('mailer') ?: config('mail.default', 'smtp');
        $config = config("mail.mailers.{$mailerName}");

        if (!$config) {
            $this->components->error("Il mailer [{$mailerName}] non è definito in config/mail.php.");
            return self::FAILURE;
        }

        $transportName = $config['transport'] ?? $mailerName;
        if ($transportName !== 'smtp') {
            $this->components->warn("Il mailer selezionato usa il trasporto [{$transportName}]. Verranno comunque verificati i parametri SMTP.");
        }

        $this->components->info('Parametri SMTP configurati:');

        $required = [
            'host' => 'MAIL_HOST',
            'port' => 'MAIL_PORT',
            'username' => 'MAIL_USERNAME',
            'password' => 'MAIL_PASSWORD',
            'encryption' => 'MAIL_ENCRYPTION',
        ];

        $rows = [];
        $missing = [];

        foreach ($required as $key => $env) {
            $value = $config[$key] ?? null;
            if (is_string($value) && $key === 'password') {
                $display = str_repeat('*', strlen($value));
            } elseif (is_string($value) && $value !== '') {
                $display = $value;
            } else {
                $display = '<vuoto>';
                $missing[] = $env;
            }

            $rows[] = [$key, $display, $env];
        }

        $this->table(['Parametro', 'Valore', 'Variabile .env'], $rows);

        if ($missing) {
            $this->components->error('Parametri mancanti: '.implode(', ', $missing));
            return self::FAILURE;
        }

        $this->components->info('Tentativo di connessione al server SMTP...');

        try {
            $transport = $mailManager->createSymfonyTransport(['transport' => 'smtp', ...$config]);
            if ($transport instanceof EsmtpTransport) {
                $transport->start();
                $transport->stop();
                $this->components->info('Connessione SMTP riuscita.');
                return self::SUCCESS;
            }

            $this->components->warn('Il trasporto risultante non è SMTP; verifica la configurazione manualmente.');
            return self::FAILURE;
        } catch (\Throwable $throwable) {
            $this->components->error('Connessione fallita: '.$throwable->getMessage());
            return self::FAILURE;
        }
    }
}
