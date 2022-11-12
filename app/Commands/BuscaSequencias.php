<?php

namespace App\Commands;

use App\UF;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

class BuscaSequencias extends Command
{
    protected $signature = 'logs:sequencias {uf} {turno} {segundos} {quantidade=5}';

    protected $description = 'Busca sequências de votos com diferença de tempo menor ou igual aos segundos informados';

    public function handle(): int
    {
        try {
            /**
             * @var \App\UF $uf
             * @var int $turno
             * @var int $segundos
             * @var int $quantidade
             */
            [$uf, $turno, $segundos, $quantidade] = $this->argumentos();
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return Command::INVALID;
        }

        if (! Storage::exists('sequencias')) {
            Storage::makeDirectory('sequencias');
        }

        $arquivo = \storage_path("app/sequencias/{$uf->value}-{$turno}t-{$segundos}s-{$quantidade}q.csv");

        if (($handle = \fopen($arquivo, 'w')) === false) {
            $this->error('Falha ao criar o arquivo: ' . $arquivo);

            return Command::FAILURE;
        }

        $this->info('Iniciando consulta...');

        \fputcsv($handle, ['uf', 'id', 'arquivo', 'data_hora', 'segundos_depois'], ';');

        $sql = $this->consulta($uf, $turno, $segundos);

        $linha = 0;
        $sequencias = 0;
        $sequencia = [];
        $anterior = null;
        foreach (DB::cursor($sql) as $linha => $registro) {
            if ($linha === 0) {
                $this->info('Analisando os dados...');
            }

            if ($anterior?->id !== $registro->id_anterior) {
                $sequencias += $this->gravaSequencia($handle, $uf, $quantidade, $arquivo, $sequencia);
                $sequencia = [];
            }

            $sequencia[] = $registro;
            $anterior = $registro;

            if (($linha + 1) % 1000 === 0) {
                $this->comment(\number_format($linha + 1) . ' linhas processadas');
            }
        }

        if ($linha > 0) {
            $this->comment(\number_format($linha + 1) . ' linhas processadas');
        }

        \fclose($handle);

        $this->info('Arquivo gravado: ' . $arquivo);
        $this->info('Sequências encontradas: ' . $sequencias);

        return Command::SUCCESS;
    }

    public function gravaSequencia($handle, UF $uf, int $quantidade, string $arquivo, array $sequencia): int
    {
        // o primeiro registro serve apenas para indicar o início da sequência
        if (\count($sequencia) - 1 < $quantidade) {
            return 0;
        }

        foreach ($sequencia as $indice => $registro) {
            \fputcsv($handle, [
                'uf' => $uf->value,
                'id' => $registro->id,
                'arquivo' => $arquivo,
                'data_hora' => $registro->data_hora,
                'segundos_depois' => $indice > 0 ? $registro->segundos_depois : -1,
            ], ';');
        }

        return 1;
    }

    private function consulta(UF $uf, int $turno, int $segundos): string
    {
        $sql = <<<'SQL'
        WITH entrada AS (
            SELECT
                id,
                arquivo,
                data_hora,
                LAG(id, 1, NULL) OVER janela AS id_anterior,
                TIMESTAMPDIFF(SECOND, LAG(data_hora, 1, NULL) OVER janela, data_hora) AS segundos_depois
            FROM logs_%s_%dt
            WHERE
                voto_computado = 1
                WINDOW janela AS (PARTITION BY arquivo ORDER BY data_hora))
        SELECT
            '%s' AS uf,
            id,
            arquivo,
            data_hora,
            id_anterior,
            segundos_depois
        FROM entrada
        WHERE segundos_depois BETWEEN 0 AND %d
        ORDER BY arquivo, data_hora
        SQL;

        return \sprintf($sql, Str::lower($uf->value), $turno, $uf->value, $segundos);
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

        $segundos = $this->argument('segundos');

        if (\preg_match('/^[1-9]\d*$/', $segundos) !== 1) {
            throw new \InvalidArgumentException('Segundos deve ser um número inteiro igual ou maior que um');
        }

        $quantidade = $this->argument('quantidade');

        if (\preg_match('/^[1-9]\d*$/', $quantidade) !== 1) {
            throw new \InvalidArgumentException('Quantidade deve ser um número inteiro igual ou maior que um');
        }

        return [$uf, \intval($turno), \intval($segundos), \intval($quantidade)];
    }
}
