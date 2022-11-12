<?php

namespace App\Commands;

use App\UF;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

class CalculaFrequencias extends Command
{
    protected $signature = 'logs:frequencias {uf} {turno}';

    protected $description = 'Calcula a frequência de votos por segundos após o último voto';

    public function handle(): int
    {
        try {
            /**
             * @var \App\UF $uf
             * @var int $turno
             */
            [$uf, $turno] = $this->argumentos();
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return Command::INVALID;
        }

        if (! Storage::exists('frequencias')) {
            Storage::makeDirectory('frequencias');
        }

        $arquivo = \storage_path("app/frequencias/{$uf->value}-{$turno}t.csv");

        if (($handle = \fopen($arquivo, 'w')) === false) {
            $this->error('Falha ao criar o arquivo: ' . $arquivo);

            return Command::FAILURE;
        }

        \fputcsv($handle, ['uf', 'segundos_depois', 'frequencia'], ';');

        $this->info('Iniciando consulta...');

        $sql = $this->consulta($uf, $turno);

        $linha = 0;
        foreach (DB::cursor($sql) as $linha => $registro) {
            if ($linha === 0) {
                $this->info('Analisando os dados...');
            }

            $registro->segundos_depois ??= -1;

            \fputcsv($handle, (array) $registro, ';');

            if (($linha + 1) % 1000 === 0) {
                $this->comment(\number_format($linha + 1) . ' linhas processadas');
            }
        }

        if ($linha > 0) {
            $this->comment(\number_format($linha + 1) . ' linhas processadas');
        }

        \fclose($handle);

        $this->info('Arquivo gravado: ' . $arquivo);

        return Command::SUCCESS;
    }

    private function consulta(UF $uf, int $turno): string
    {
        $sql = <<<'SQL'
        WITH entrada AS (
            SELECT
                id,
                TIMESTAMPDIFF(SECOND, LAG(data_hora, 1, NULL) OVER janela, data_hora) AS segundos_depois
            FROM logs_%s_%st
            WHERE
                voto_computado = 1
            WINDOW janela AS (PARTITION BY arquivo ORDER BY data_hora)
        )
        SELECT
            '%s' AS 'uf',
            segundos_depois,
            COUNT(*) AS frequencia
        FROM entrada
        GROUP BY segundos_depois
        ORDER BY segundos_depois
        SQL;

        return \sprintf($sql, Str::lower($uf->value), $turno, $uf->value);
    }

    private function argumentos(): array
    {
        $uf = UF::tryFrom($this->argument('uf'));

        if (\is_null($uf)) {
            throw new \InvalidArgumentException('UF inválida');
        }

        $turno = $this->argument('turno');

        if (! \in_array($turno, ['1', '2'])) {
            throw new \InvalidArgumentException('Turno deve ser 1 ou 2');
        }

        return [$uf, \intval($turno)];
    }
}
