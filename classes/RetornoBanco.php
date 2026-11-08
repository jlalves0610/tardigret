<?php

declare(strict_types=1);

use Monolog\Logger;


/*
 * IMPORTADOR CNAB 240 - CAIXA
 * Pagamento a Fornecedores
 * PHP 8.4 / PostgreSQL 15
 */

class RetornoBanco
{
    private PDO $pdo;
    private Logger $logger;
    private array $erros = [];

    // public function __construct(PDO $pdo)
    // {
    //     $this->pdo = $pdo;
    // }
    public function __construct(PDO $pdo, Logger $logger)
    {
        $this->logger = $logger;
        $this->pdo = $pdo;
    }

    /*
     * Extrai campo por posição fixa.
     * As posições recebidas são base 1.
     */
    private function campo(
        string $linha,
        int $inicio,
        int $tamanho
    ): string {

        return trim(substr($linha, $inicio - 1, $tamanho));
    }

    /*
     * Faz a leitura do arquivo CNAB.
     */
    public function processar(string $arquivo): array
    {
        $nomeArquivo = basename($arquivo);
        $this->logger->info('Iniciando processamento do arquivo: ' . $nomeArquivo);

        if (!is_file($arquivo) || !is_readable($arquivo)) {
            $this->logger->error('Arquivo não encontrado ou sem permissão: ' . $nomeArquivo);
            throw new RuntimeException('Arquivo não encontrado ou sem permissão.');
        }

        $hash = hash_file('sha256', $arquivo);

        if ($hash === false) {
            $this->logger->error('Não foi possível calcular o hash: ' . $nomeArquivo);
            throw new RuntimeException('Não foi possível calcular o hash do arquivo.');
        }

        $resultado = [
            'arquivo' => $nomeArquivo,
            'hash' => $hash,
            'lotes' => [],
            'erros' => [],
        ];

        $loteAtual = null;
        $indiceA = null;
        $indiceJ = null;
        $linhaA = null;

        $indiceDda = null;
        $ultimoNsrDda = null;

        $handle = fopen($arquivo, 'rb');

        if ($handle === false) {
            $this->logger->error('Não foi possível abrir o arquivo: ' . $nomeArquivo);
            throw new RuntimeException('Não foi possível abrir o arquivo.');
        }

        try {
            $numeroLinha = 0;

            while (($linha = fgets($handle)) !== false) {
                $numeroLinha++;
                $linha = rtrim($linha, "\r\n");

                if ($numeroLinha === 1 && str_starts_with($linha, "\xEF\xBB\xBF")) {
                    $linha = substr($linha, 3);
                }

                if ($linha === '') {
                    continue;
                }

                $tamanho = strlen($linha);

                if ($tamanho !== 240) {
                    $indiceDda = null;
                    $ultimoNsrDda = null;

                    $this->logger->error(
                        "Linha {$numeroLinha}: Registro diferente de 240 posições (tamanho: {$tamanho})"
                    );

                    $resultado['erros'][] = [
                        'linha' => $numeroLinha,
                        'erro' => 'Registro diferente de 240 posições',
                        'tamanho' => $tamanho,
                    ];

                    continue;
                }

                $banco = substr($linha, 0, 3);
                $lote = substr($linha, 3, 4);
                $tipoRegistro = substr($linha, 7, 1);

                if ($banco !== '104') {
                    $indiceDda = null;
                    $ultimoNsrDda = null;

                    $this->logger->error(
                        "Linha {$numeroLinha}: Código de banco diferente de 104 (código: {$banco})"
                    );

                    $resultado['erros'][] = [
                        'linha' => $numeroLinha,
                        'erro' => 'Código de banco diferente de 104',
                    ];

                    continue;
                }

                // Header de lote.
                if ($tipoRegistro === '1') {
                    $loteAtual = $lote;
                    $indiceA = null;
                    $indiceJ = null;
                    $linhaA = null;
                    $indiceDda = null;
                    $ultimoNsrDda = null;
                    unset($favorecido);

                    $tipoCompromisso = $this->campo($linha, 39, 2);

                    $resultado['lotes'][$loteAtual] = [
                        'numero_lote' => $loteAtual,
                        'tipo_inscricao' => $this->campo($linha, 18, 1),
                        'inscricao' => $this->campo($linha, 19, 14),
                        'forma_lancamento' => $this->campo($linha, 12, 2),
                        'codigo_convenio' => $this->campo($linha, 33, 6),
                        'tipo_compromisso' => $tipoCompromisso,
                        'codigo_compromisso' => $this->campo($linha, 41, 4),
                        'parametro_transmissao' => $this->campo($linha, 45, 2),
                        'tipo_cadastro_pessoa' => $this->TipoCadastroPessoa($tipoCompromisso),
                        'favorecidos' => [],
                        'dda' => [],
                    ];

                    continue;
                }

                // Trailer de lote.
                if ($tipoRegistro === '5') {
                    $loteAtual = null;
                    $indiceA = null;
                    $indiceJ = null;
                    $linhaA = null;
                    $indiceDda = null;
                    $ultimoNsrDda = null;
                    unset($favorecido);

                    continue;
                }

                if ($tipoRegistro !== '3') {
                    continue;
                }

                if (
                    $loteAtual === null
                    || $lote !== $loteAtual
                    || !isset($resultado['lotes'][$loteAtual])
                ) {
                    $indiceA = null;
                    $indiceJ = null;
                    $linhaA = null;
                    $indiceDda = null;
                    $ultimoNsrDda = null;
                    unset($favorecido);

                    $mensagem = "Registro detalhe sem header correspondente ao lote {$lote}.";
                    $this->logger->error("Linha {$numeroLinha}: {$mensagem}");

                    $resultado['erros'][] = [
                        'linha' => $numeroLinha,
                        'erro' => $mensagem,
                    ];

                    continue;
                }

                $segmento = substr($linha, 13, 1);
                $ehY03 = $segmento === 'Y' && $this->campo($linha, 18, 2) === '03';

                if ($segmento === 'G' || $segmento === 'H' || $ehY03) {
                    $indiceA = null;
                    $indiceJ = null;
                    $linhaA = null;
                    unset($favorecido);

                    try {
                        if ($segmento === 'G') {
                            // Cada G abre uma nova entrada, mesmo quando o NSR se repete.
                            $resultado['lotes'][$loteAtual]['dda'][] = $this->lerDdaG($linha);

                            $indiceDda = array_key_last($resultado['lotes'][$loteAtual]['dda']);
                            $ultimoSegmentoDda = 'G';

                            continue;
                        }

                        if ($segmento === 'H') {
                            if ($indiceDda === null || $ultimoSegmentoDda !== 'G') {
                                throw new UnexpectedValueException(
                                    "Segmento H sem G anterior no lote {$loteAtual}."
                                );
                            }

                            $resultado['lotes'][$loteAtual]['dda'][$indiceDda] +=
                                $this->lerDdaH($linha);

                            $ultimoSegmentoDda = 'H';

                            continue;
                        }

                        // Y(03) complementa o G diretamente ou depois do H.
                        if (
                            $indiceDda === null
                            || !in_array($ultimoSegmentoDda, ['G', 'H'], true)
                        ) {
                            throw new UnexpectedValueException(
                                "Segmento Y(03) sem título DDA correspondente no lote {$loteAtual}."
                            );
                        }

                        $resultado['lotes'][$loteAtual]['dda'][$indiceDda]['sacado'] =
                            $this->lerDdaY03($linha);

                        $indiceDda = null;
                        $ultimoSegmentoDda = null;
                    } catch (UnexpectedValueException | OverflowException $e) {
                        $this->logger->error("Linha {$numeroLinha}: " . $e->getMessage());

                        $resultado['erros'][] = [
                            'linha' => $numeroLinha,
                            'erro' => $e->getMessage(),
                        ];

                        $indiceDda = null;
                        $ultimoSegmentoDda = null;
                    }

                    continue;
                }

                // Um detalhe de outra família encerra a associação DDA.
                $indiceDda = null;
                $ultimoSegmentoDda = null;

                if ($segmento === 'A') {
                    $indiceJ = null;
                    $linhaA = $linha;
                    unset($favorecido);

                    $resultado['lotes'][$loteAtual]['favorecidos'][] = [
                        'codigo_banco' => $this->campo($linha, 21, 3),
                        'codigo_agencia' => $this->campo($linha, 24, 5),
                        'dv_agencia' => $this->campo($linha, 29, 1),
                        'conta_corrente' => $this->campo($linha, 30, 12),
                        'dv_conta' => $this->campo($linha, 42, 1),
                        'nome_terceiro' => $this->campo($linha, 44, 30),
                        'tipo_modeda' => $this->tipo_modeda($this->campo($linha, 102, 3)),
                        'tipo_inscricao' => null,
                        'numero_inscricao' => null,
                        'logradouro' => null,
                    ];

                    $indiceA = array_key_last($resultado['lotes'][$loteAtual]['favorecidos']);

                    continue;
                }

                if ($segmento === 'B') {
                    $indiceJ = null;

                    if ($indiceA === null) {
                        $mensagem = 'Segmento B sem segmento A correspondente';
                        $this->logger->error("Linha {$numeroLinha}: {$mensagem}");

                        $resultado['erros'][] = [
                            'linha' => $numeroLinha,
                            'erro' => $mensagem,
                        ];

                        continue;
                    }

                    $favorecido = &$resultado['lotes'][$loteAtual]['favorecidos'][$indiceA];

                    $favorecido['tipo_inscricao'] = $this->campo($linha, 18, 1);
                    $favorecido['numero_inscricao'] = $this->campo($linha, 19, 14);
                    $favorecido['logradouro'] = $this->campo($linha, 33, 30);
                    $favorecido['numero_local'] = $this->campo($linha, 63, 5);
                    $favorecido['complemento'] = $this->campo($linha, 68, 15);
                    $favorecido['bairro'] = $this->campo($linha, 83, 15);
                    $favorecido['cidade'] = $this->campo($linha, 98, 20);
                    $favorecido['cep'] = $this->campo($linha, 118, 8);
                    $favorecido['uf'] = $this->campo($linha, 126, 2);
                    $favorecido['tpcadastropessoa'] = 1;

                    $pagamento = $this->lerPagamentoLiquidadoA($linhaA);

                    if ($pagamento !== null) {
                        $favorecido += $pagamento;
                    }

                    // A referência fica disponível para o segmento Z.
                    $indiceA = null;
                    $linhaA = null;

                    continue;
                }

                if ($segmento === 'J') {
                    $indiceA = null;
                    $linhaA = null;
                    unset($favorecido);

                    $nsr = (int) $this->campo($linha, 9, 5);

                    $ehJ52 = substr($linha, 14, 3) === '   '
                        && $this->campo($linha, 18, 2) === '52';

                    if (!$ehJ52) {
                        $codigoMoeda = $this->campo($linha, 21, 1);
                        $ocorrencias = str_split(substr($linha, 230, 10), 2);

                        $resultado['lotes'][$loteAtual]['favorecidos'][] = [
                            'segmento' => 'J',
                            'nsr' => $nsr,
                            'linha_j' => $numeroLinha,
                            'j52_recebido' => false,
                            'pix_qr_code' => $codigoMoeda === '0',
                            'codigo_banco' => null,
                            'banco_destino' => $this->campo($linha, 18, 3),
                            'codigo_agencia' => null,
                            'dv_agencia' => null,
                            'conta_corrente' => null,
                            'dv_conta' => null,
                            'nome_terceiro' => $this->campo($linha, 62, 30),
                            'tipo_modeda' => match ($codigoMoeda) {
                                '0', '9' => $this->tipo_modeda('BRL'),
                                '2' => 2,
                                default => 0,
                            },
                            'tipo_inscricao' => null,
                            'numero_inscricao' => null,
                            'logradouro' => null,
                            'codigobarras' => $codigoMoeda === '0' ? null : $this->campo($linha, 18, 44),
                            'data_vencimento' => $this->campo($linha, 92, 8),
                            'valor_titulo' => $this->valorCnab($linha, 100),
                            'valor_desconto' => $this->valorCnab($linha, 115),
                            'valor_mora_multa' => $this->valorCnab($linha, 130),
                            'data_pagamento' => $this->campo($linha, 145, 8),
                            'valor_pagamento' => $this->valorCnab($linha, 153),
                            'documento_empresa' => $this->campo($linha, 183, 6),
                            'documento_banco' => $this->campo($linha, 203, 9),
                            'ocorrencias' => array_values(array_filter(
                                $ocorrencias,
                                static fn(string $codigo): bool => trim($codigo) !== ''
                            )),
                        ];

                        $indiceJ = array_key_last($resultado['lotes'][$loteAtual]['favorecidos']);

                        continue;
                    }

                    $j = $indiceJ === null
                        ? null
                        : $resultado['lotes'][$loteAtual]['favorecidos'][$indiceJ];

                    if ($j === null || $nsr !== $j['nsr'] + 1) {
                        $mensagem = 'Segmento J52 sem segmento J correspondente';
                        $this->logger->error("Linha {$numeroLinha}: {$mensagem}");

                        $resultado['erros'][] = [
                            'linha' => $numeroLinha,
                            'erro' => $mensagem,
                        ];

                        $indiceJ = null;

                        continue;
                    }

                    $j['j52_recebido'] = true;
                    $j['nsr_j52'] = $nsr;
                    $j['tipo_inscricao_pagador'] = $this->campo($linha, 20, 1);
                    $j['numero_inscricao_pagador'] = $this->campo($linha, 21, 15);
                    $j['nome_pagador'] = $this->campo($linha, 36, 40);
                    $j['tipo_inscricao_beneficiario'] = $this->campo($linha, 76, 1);
                    $j['numero_inscricao_beneficiario'] = $this->campo($linha, 77, 15);
                    $j['nome_beneficiario'] = $this->campo($linha, 92, 40);

                    if ($j['pix_qr_code']) {
                        $j['url_chave'] = $this->campo($linha, 132, 79);
                        $j['txid'] = $this->campo($linha, 211, 30);
                    } else {
                        $j['tipo_inscricao_avalista'] = $this->campo($linha, 132, 1);
                        $j['numero_inscricao_avalista'] = $this->campo($linha, 133, 15);
                        $j['nome_avalista'] = $this->campo($linha, 148, 40);
                    }

                    $j['tipo_inscricao'] = $j['tipo_inscricao_beneficiario'];
                    $j['numero_inscricao'] = $this->cpfCnpjBeneficiario(
                        $j['tipo_inscricao_beneficiario'],
                        $j['numero_inscricao_beneficiario']
                    );

                    if ($j['nome_beneficiario'] !== '') {
                        $j['nome_terceiro'] = $j['nome_beneficiario'];
                    }

                    $resultado['lotes'][$loteAtual]['favorecidos'][$indiceJ] = $j;
                    $indiceJ = null;

                    continue;
                }

                if ($segmento === 'Z') {
                    $protocolo = $this->campo($linha, 79, 25);

                    if (isset($favorecido) && $protocolo !== '') {
                        $favorecido['segmento_z'] = [
                            'sequencial' => $this->campo($linha, 9, 5),
                            'protocolo_bancario' => $protocolo,
                        ];
                    }

                    unset($favorecido);

                    continue;
                }

                $indiceA = null;
                $indiceJ = null;
                $linhaA = null;
                unset($favorecido);
            }

            if (!feof($handle)) {
                throw new RuntimeException('Falha durante a leitura do arquivo CNAB.');
            }
        } finally {
            fclose($handle);
        }

        $resultado['erros'] = array_merge($resultado['erros'], $this->erros);

        return $resultado;
    }

    private function tipo_modeda(string $moeda): int
    {

        switch ($moeda) {
            case 'BRL':
                return 9;
            case 'USD':
                return 2;
            case 'UFR':
                return 6;
            case 'TRD':
                return 4;
            default:
                return 0; // Desconhecida
        }
    }
    private function TipoCadastroPessoa(String $Tipocompromisso): int
    {
        $tipocadastropessoa = 0;
        switch ($Tipocompromisso) {
            case "01": //PAGAMENTO A FORCECEDOR
                $tipocadastropessoa = 2;
                break;

            case "02": //PAGAMENTO SALARIO
                $tipocadastropessoa = 1;
                break;

            case "03": //AUTOPAGAMENTO
                $tipocadastropessoa = 2;
                break;

            case "06": //SALARIO AMPLIACAO DE BASE
                $tipocadastropessoa = 1;
                break;

            case "11": //DEBITO EM CONTA
                $tipocadastropessoa = 3;
                break;

            default:
                $tipocadastropessoa = 2;
                break;
        }
        return $tipocadastropessoa;
    }

    /*
     * Grava os dados no PostgreSQL.
     */
    public function gravar(array $dados): array
    {
        if (!empty($dados['erros'])) {
            throw new RuntimeException(
                'O retorno SIACC contém erros de leitura. Consulte o log da importação.'
            );
        }

        $totalFavorecidos = 0;
        $totalDda = 0;

        if ($this->validararquivoretorno($dados['hash'])) {
            $this->logger->error(
                'O arquivo de retorno já foi processado anteriormente. O hash do arquivo é: ' . $dados['hash']
            );
            throw new RuntimeException(
                'O arquivo de retorno já foi processado anteriormente. O hash do arquivo é: ' . $dados['hash']
            );
        }

        $this->pdo->beginTransaction();

        try {
            foreach ($dados['lotes'] as $lote) {
                // $idcedente = $this->buscarIdCedenteDoLote($lote);

                $totalDda += $this->gravarDda($lote['dda'] ?? [], 3);

                $totalFavorecidos += $this->gravarFavorecidosDoLote($lote, 1);
            }
            //GRAVAR TABELA DE CONTROLE DE ARQUIVO DE RETORNO
            if ($totalFavorecidos === 0 && $totalDda === 0) {
                throw new RuntimeException(
                    'Nenhum favorecido ou DDA foi gravado. O arquivo de retorno não será registrado.'
                );
            } else {
                $this->gravarcontrolearquivo($dados);
            }
            $this->pdo->commit();

            return [
                'lotes_gravados' => 0,
                'favorecidos_gravados' => $totalFavorecidos,
                'dda_gravados' => $totalDda,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            print_r($e);
            throw $e;
        }
    }


    private function validararquivoretorno(String $hash): bool
    {
        $sql = <<<'SQL'
        SELECT COUNT(*) AS total FROM tardig.retorno_processado
        WHERE hash = :hash
        SQL;

        $stmt = $this->pdo->prepare($sql);

        if ($stmt === false || !$stmt->execute(['hash' => $hash])) {
            throw new RuntimeException('Falha ao consultar o hash do arquivo de retorno.');
        }

        $total = (int) $stmt->fetchColumn();

        return $total > 0;
    }


    private function buscarIdCedenteDoLote(array $lote): int
    {
        $sql = <<<'SQL'
        SELECT idcedente
        FROM pagamento.cedentes
        WHERE cpf_cnpj = :cpf_cnpj
          AND codigocedente = :codigo_cedente
        SQL;

        $stmt = $this->pdo->prepare($sql);

        if ($stmt === false || !$stmt->execute([
            'cpf_cnpj' => $lote['inscricao'],
            'codigo_cedente' => $lote['codigo_convenio'],
        ])) {
            throw new RuntimeException(
                "Falha ao consultar o cedente do lote {$lote['numero_lote']}."
            );
        }

        $idcedente = $stmt->fetchColumn();

        if ($idcedente === false) {
            $mensagem = "Não foi possível recuperar o ID do cedente do lote {$lote['numero_lote']}.";

            $this->logger->error($mensagem, [
                'codigo_convenio' => $lote['codigo_convenio'],
            ]);

            throw new RuntimeException($mensagem);
        }

        return (int) $idcedente;
    }

    private function gravarFavorecidosDoLote(array $lote, int $idcedente): int
    {
        $favorecidos = $lote['favorecidos'] ?? [];

        if ($favorecidos === []) {
            return 0;
        }

        $sql = <<<'SQL'
        INSERT INTO pagamento.pessoas (
            idcedente, idbanco, agencia, dvagencia,
            conta, dvconta, nome, cpf_cnpj,
            endereco, numero, bairro, cep,
            cidade, estado, idtipocadastropessoa, idmoeda,
            usuario_cadastro
        )
        VALUES (
            :idcedente, :banco, :agencia, :dvagencia,
            :conta, :dvconta, :nome, :cpf_cnpj,
            :endereco, :numero, :bairro, :cep,
            :cidade, :estado, :idtipocadastropessoa, :idmoeda,
            :usuario_cadastro
        )
        ON CONFLICT ON CONSTRAINT pessoas_cadastro_unique
        DO NOTHING
        SQL;

        $stmt = $this->pdo->prepare($sql);

        if ($stmt === false) {
            throw new RuntimeException('Falha ao preparar o INSERT de pagamento.pessoas.');
        }

        $gravados = 0;

        foreach ($favorecidos as $favorecido) {
            if (
                ($favorecido['segmento'] ?? null) === 'J'
                && (
                    !($favorecido['j52_recebido'] ?? false)
                    || ($favorecido['numero_inscricao'] ?? null) === null
                    || ($favorecido['nome_terceiro'] ?? '') === ''
                )
            ) {
                $this->logger->warning(
                    'Beneficiário do segmento J não cadastrado por falta de identificação',
                    [
                        'lote' => $lote['numero_lote'],
                        'linha' => $favorecido['linha_j'] ?? null,
                        'nsr' => $favorecido['nsr'] ?? null,
                    ]
                );

                continue;
            }

            $inseriu = $stmt->execute([
                'idcedente' => $idcedente,
                'banco' => $favorecido['codigo_banco'],
                'agencia' => $favorecido['codigo_agencia'],
                'dvagencia' => $favorecido['dv_agencia'],
                'conta' => $favorecido['conta_corrente'],
                'dvconta' => $favorecido['dv_conta'],
                'nome' => $favorecido['nome_terceiro'],
                'cpf_cnpj' => $favorecido['numero_inscricao'],
                'endereco' => $favorecido['logradouro'],
                'numero' => $favorecido['numero_local'] ?? null,
                'bairro' => $favorecido['bairro'] ?? null,
                'cep' => $favorecido['cep'] ?? null,
                'cidade' => $favorecido['cidade'] ?? null,
                'estado' => $favorecido['uf'] ?? null,
                'idtipocadastropessoa' => $lote['tipo_cadastro_pessoa'],
                'idmoeda' => $favorecido['tipo_modeda'],
                'usuario_cadastro' => 'importador',
            ]);

            if (!$inseriu) {
                throw new RuntimeException(
                    "Falha ao gravar favorecido do lote {$lote['numero_lote']}."
                );
            }

            $gravados += $stmt->rowCount();
        }

        return $gravados;
    }

    private function valorCnab(string $linha, int $inicio): ?string
    {
        $valor = $this->campo($linha, $inicio, 15);

        if (strlen($valor) !== 15 || !ctype_digit($valor)) {
            return null;
        }

        $inteiro = ltrim(substr($valor, 0, -2), '0');

        return ($inteiro === '' ? '0' : $inteiro) . '.' . substr($valor, -2);
    }

    private function cpfCnpjBeneficiario(string $tipo, string $inscricao): ?string
    {
        if (strlen($inscricao) !== 15 || !ctype_digit($inscricao)) {
            return null;
        }

        $documento = match ($tipo) {
            '1' => str_starts_with($inscricao, '0000') ? substr($inscricao, 4) : null,
            '2' => str_starts_with($inscricao, '0') ? substr($inscricao, 1) : null,
            default => null,
        };

        return $documento !== null && ltrim($documento, '0') !== ''
            ? $documento
            : null;
    }
    private function lerPagamentoLiquidadoA(string $linhaA): ?array
    {
        $ocorrencias = array_values(array_filter(
            str_split(substr($linhaA, 230, 10), 2),
            static fn(string $codigo): bool => trim($codigo) !== ''
        ));

        $codigosEfetivados = ['00', '03'];

        if ($ocorrencias === [] || array_diff($ocorrencias, $codigosEfetivados) !== []) {
            return null;
        }

        $dataBruta = $this->campo($linhaA, 155, 8);
        $valorBruto = $this->campo($linhaA, 163, 15);

        if (
            strlen($dataBruta) !== 8 || !ctype_digit($dataBruta)
            || strlen($valorBruto) !== 15 || !ctype_digit($valorBruto)
            || ltrim($valorBruto, '0') === ''
        ) {
            return null;
        }

        $data = \DateTimeImmutable::createFromFormat('!dmY', $dataBruta);

        if ($data === false || $data->format('dmY') !== $dataBruta) {
            return null;
        }

        $parteInteira = ltrim(substr($valorBruto, 0, -2), '0');

        return [
            'documento_empresa' => $this->campo($linhaA, 74, 6),
            'data_pagamento'    => $data->format('Y-m-d'),
            'valor_pago'        => ($parteInteira === '' ? '0' : $parteInteira)
                . '.' . substr($valorBruto, -2),
            'ocorrencias'       => $ocorrencias,
        ];
    }
    private function lerDdaY03(string $linha): array
    {
        $cep = $this->campo($linha, 131, 8);

        return [
            'tipo_inscricao'   => $this->campo($linha, 20, 1),
            'numero_inscricao' => $this->campo($linha, 21, 15),
            'nome'             => $this->campo($linha, 36, 40),
            'endereco'         => $this->campo($linha, 76, 40),
            'bairro'           => $this->campo($linha, 116, 15),
            'cep'              => $cep === '' || $cep === '00000000' ? null : $cep,
            'cidade'           => $this->campo($linha, 139, 15),
            'uf'               => $this->campo($linha, 154, 2),
        ];
    }
    private function lerDdaG(string $linha): array
    {
        $tipo = $this->campo($linha, 62, 1);
        $inscricao = $this->campo($linha, 63, 15);

        // Remove apenas o preenchimento excedente à esquerda.
        $padrao = match ($tipo) {
            '1' => '/\A0*(\d{11})\z/',
            '2' => '/\A0*(\d{14})\z/',
            default => null,
        };

        if (
            $padrao === null
            || preg_match($padrao, $inscricao, $partes) !== 1
            || trim($partes[1], '0') === ''
        ) {
            throw new UnexpectedValueException(
                'Segmento G sem CPF/CNPJ utilizável na tabela dados_dda.'
            );
        }

        $codigoBarras = $this->campo($linha, 18, 44);

        if (preg_match('/\A\d{44}\z/', $codigoBarras) !== 1) {
            throw new UnexpectedValueException('Código de barras inválido no segmento G.');
        }

        return [
            'nome'                => $this->campo($linha, 78, 30),
            'cpfcnpj'             => $partes[1],
            'tipo_contribuinte'   => $tipo,
            'idbanco'             => (int) substr($codigoBarras, 0, 3),
            'codmovremessa'       => $this->campo($linha, 16, 2),
            'juros'               => $this->decimalDda($linha, 190),
            'codigodesconto1'     => $this->inteiroDda($linha, 205),
            'datadesconto1'       => $this->dataDda($linha, 206),
            'desconto1'           => $this->decimalDda($linha, 214),
            'codigoprotesto'      => $this->inteiroDda($linha, 229),
            'diasprotesto'        => $this->inteiroDda($linha, 230, 2),
            'datalimitepagamento' => $this->dataDda($linha, 232),
            'codigobarras'        => $codigoBarras,
        ];
    }

    private function lerDdaH(string $linha): array
    {
        $inscricao = $this->campo($linha, 19, 15);
        $nome = $this->campo($linha, 34, 40);

        return [
            'inscricao_avalista' => trim($inscricao, '0') === '' ? null : $inscricao,
            'nomeavalista'       => $nome === '' ? null : $nome,
            'codigodesconto2'    => $this->inteiroDda($linha, 74),
            'datadesconto2'      => $this->dataDda($linha, 75),
            'valordesconto2'     => $this->decimalDda($linha, 83),
            'codigodesconto3'    => $this->inteiroDda($linha, 98),
            'datadesconto3'      => $this->dataDda($linha, 99),
            'valordesconto3'     => $this->decimalDda($linha, 107),
            'codigomulta'        => $this->inteiroDda($linha, 122),
            'datamulta'          => $this->dataDda($linha, 123),
            'valormulta'         => $this->decimalDda($linha, 131),
            'valorabatimento'    => $this->decimalDda($linha, 146),
        ];
    }

    private function inteiroDda(string $linha, int $inicio, int $tamanho = 1): ?int
    {
        $valor = $this->campo($linha, $inicio, $tamanho);

        if ($valor === '') {
            return null;
        }

        if (!ctype_digit($valor)) {
            throw new UnexpectedValueException("Campo numérico inválido na posição {$inicio}.");
        }

        return (int) $valor;
    }

    private function dataDda(string $linha, int $inicio): ?string
    {
        $valor = $this->campo($linha, $inicio, 8);

        if ($valor === '' || $valor === '00000000') {
            return null;
        }

        $data = DateTimeImmutable::createFromFormat('!dmY', $valor);

        if ($data === false || $data->format('dmY') !== $valor) {
            throw new UnexpectedValueException("Data DDA inválida na posição {$inicio}.");
        }

        return $valor;
    }

    private function decimalDda(string $linha, int $inicio): ?string
    {
        $valor = $this->campo($linha, $inicio, 15);

        if ($valor === '') {
            return null;
        }

        if (strlen($valor) !== 15 || !ctype_digit($valor)) {
            throw new UnexpectedValueException("Valor DDA inválido na posição {$inicio}.");
        }

        $inteiro = ltrim(substr($valor, 0, -2), '0');

        if (strlen($inteiro) > 9) {
            throw new OverflowException("Valor na posição {$inicio} excede numeric(11,2).");
        }

        return ($inteiro === '' ? '0' : $inteiro) . '.' . substr($valor, -2);
    }
    private function gravarcontrolearquivo(array $dados): void
    {
        $sql = <<<'SQL'
        INSERT INTO tardig.retorno_processado (nomearquivo ,hash)
        VALUES (:nomearquivo, :hash)
        SQL;

        $stmt = $this->pdo->prepare($sql);

        if ($stmt === false) {
            throw new RuntimeException('Falha ao preparar o INSERT de controle_arquivo_retorno.');
        }

        $inseriu = $stmt->execute([
            'nomearquivo' => $dados['arquivo'],
            'hash' => $dados['hash']
        ]);

        if (!$inseriu) {
            throw new RuntimeException('Falha ao gravar controle de arquivo de retorno.');
        }
    }

    private function gravarDda(array $titulos, int $idcedente): int
    {
        if ($titulos === []) {
            return 0;
        }

        if (!$this->pdo->inTransaction()) {
            throw new LogicException('A gravação do DDA exige uma transação ativa.');
        }

        $sql = <<<'SQL'
        INSERT INTO pagamento.dados_dda (
            idcedente, nome, cpfcnpj, idbanco,
            endereco, bairro, uf, cidade, cep, tipo_contribuinte,
            codigobarras, juros, codigodesconto1, datadesconto1, desconto1,
            codigoprotesto, diasprotesto, datalimitepagamento, codmovremessa,
            inscricao_avalista, nomeavalista,
            codigodesconto2, datadesconto2, valordesconto2,
            codigodesconto3, datadesconto3, valordesconto3,
            codigomulta, datamulta, valormulta, valorabatimento
        ) VALUES (
            :idcedente, :nome, :cpfcnpj, :idbanco,
            :endereco, :bairro, :uf, :cidade, :cep, :tipo_contribuinte,
            :codigobarras, :juros, :codigodesconto1, :datadesconto1, :desconto1,
            :codigoprotesto, :diasprotesto, :datalimitepagamento, :codmovremessa,
            :inscricao_avalista, :nomeavalista,
            :codigodesconto2, :datadesconto2, :valordesconto2,
            :codigodesconto3, :datadesconto3, :valordesconto3,
            :codigomulta, :datamulta, :valormulta, :valorabatimento
        )
        SQL;

        $stmt = $this->pdo->prepare($sql);

        if ($stmt === false) {
            throw new RuntimeException('Falha ao preparar o INSERT de dados_dda.');
        }

        $gravados = 0;

        foreach ($titulos as $indice => $titulo) {
            $sacado = $titulo['sacado'] ?? [];
            $cpfCnpj = trim((string) ($titulo['cpfcnpj'] ?? ''));
            $tipo = (string) ($titulo['tipo_contribuinte'] ?? '');

            $tamanhoDocumento = match ($tipo) {
                '1', '01' => 11,
                '2', '02' => 14,
                default => 0,
            };

            if (
                strlen($cpfCnpj) !== $tamanhoDocumento
                || !ctype_digit($cpfCnpj)
                || trim($cpfCnpj, '0') === ''
            ) {
                throw new UnexpectedValueException(
                    "Título DDA de índice {$indice} com CPF/CNPJ ausente ou inválido."
                );
            }

            $cep = trim((string) ($sacado['cep'] ?? ''));

            if (trim($cep, '0') === '') {
                $cep = null;
            } elseif (strlen($cep) !== 8 || !ctype_digit($cep)) {
                throw new UnexpectedValueException(
                    "Título DDA de índice {$indice} com CEP do sacado inválido."
                );
            }

            $gravou = $stmt->execute([
                'idcedente'           => $idcedente,
                'nome'                => $titulo['nome'] ?? null,
                'cpfcnpj'             => $cpfCnpj,
                'idbanco'             => $titulo['idbanco'] ?? null,
                'endereco'            => ($sacado['endereco'] ?? '') ?: null,
                'bairro'              => ($sacado['bairro'] ?? '') ?: null,
                'uf'                  => ($sacado['uf'] ?? '') ?: null,
                'cidade'              => ($sacado['cidade'] ?? '') ?: null,
                'cep'                 => $cep,
                'tipo_contribuinte'   => $tipo,
                'codigobarras'        => $titulo['codigobarras'] ?? null,
                'juros'               => $titulo['juros'] ?? null,
                'codigodesconto1'     => $titulo['codigodesconto1'] ?? null,
                'datadesconto1'       => ($titulo['datadesconto1'] ?? '') ?: null,
                'desconto1'           => $titulo['desconto1'] ?? null,
                'codigoprotesto'      => $titulo['codigoprotesto'] ?? null,
                'diasprotesto'        => $titulo['diasprotesto'] ?? null,
                'datalimitepagamento' => ($titulo['datalimitepagamento'] ?? '') ?: null,
                'codmovremessa'       => $titulo['codmovremessa'] ?? null,
                'inscricao_avalista'  => ($titulo['inscricao_avalista'] ?? '') ?: null,
                'nomeavalista'        => ($titulo['nomeavalista'] ?? '') ?: null,
                'codigodesconto2'     => $titulo['codigodesconto2'] ?? null,
                'datadesconto2'       => ($titulo['datadesconto2'] ?? '') ?: null,
                'valordesconto2'      => $titulo['valordesconto2'] ?? null,
                'codigodesconto3'     => $titulo['codigodesconto3'] ?? null,
                'datadesconto3'       => ($titulo['datadesconto3'] ?? '') ?: null,
                'valordesconto3'      => $titulo['valordesconto3'] ?? null,
                'codigomulta'         => $titulo['codigomulta'] ?? null,
                'datamulta'           => ($titulo['datamulta'] ?? '') ?: null,
                'valormulta'          => $titulo['valormulta'] ?? null,
                'valorabatimento'     => $titulo['valorabatimento'] ?? null,
            ]);

            if (!$gravou) {
                $mensagem = $stmt->errorInfo()[2] ?? 'Erro de banco sem detalhe.';
                throw new RuntimeException("Falha ao gravar o título DDA {$indice}. {$mensagem}");
            }

            $gravados++;
        }

        return $gravados;
    }
}
