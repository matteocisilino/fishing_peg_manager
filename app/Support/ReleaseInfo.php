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

namespace App\Support;

use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;

class ReleaseInfo
{
    private static ?array $cache = null;

    /**
     * @return array{version_label:string,release:string,timestamp:string}
     */
    public static function data(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $versionLabel = config('app.version_label', 'Versione 1');
        $release = self::gitDescribe() ?? self::gitCommitHash() ?? 'dev';
        $timestamp = self::gitCommitDate()
            ?? now()->timezone(config('app.timezone', 'UTC'))->format('d/m/Y H:i');

        return self::$cache = [
            'version_label' => $versionLabel,
            'release' => $release,
            'timestamp' => $timestamp,
        ];
    }

    private static function gitDescribe(): ?string
    {
        return self::runGit(['git', 'describe', '--tags', '--always']);
    }

    private static function gitCommitHash(): ?string
    {
        return self::runGit(['git', 'rev-parse', '--short', 'HEAD']);
    }

    private static function gitCommitDate(): ?string
    {
        $result = self::runGit(['git', 'log', '-1', '--format=%cd', '--date=iso8601']);
        if ($result === null) {
            return null;
        }

        try {
            return Carbon::parse($result)
                ->timezone(config('app.timezone', 'UTC'))
                ->format('d/m/Y H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function runGit(array $command): ?string
    {
        try {
            $process = new Process($command, base_path());
            $process->setTimeout(1);
            $process->run();

            if (!$process->isSuccessful()) {
                return null;
            }

            $output = trim($process->getOutput());
            return $output === '' ? null : $output;
        } catch (\Throwable) {
            return null;
        }
    }
}
