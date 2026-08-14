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

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class SecureActionOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public Carbon $expiresAt,
        public string $actionLabel = ''
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Conferma operazione sensibile',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.secure-action-otp',
            with: [
                'code' => $this->code,
                'expiresAt' => $this->expiresAt->format('d/m/Y H:i'),
                'actionLabel' => $this->actionLabel,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
