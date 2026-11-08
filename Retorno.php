
<?php
require_once './classes/RetornoBanco.php';
require_once 'importar.php';

//'pgsql:host=127.0.0.1;port=5433;dbname='tardigsys';user='tardigsysy';password=''

// Configuração PostgreSQL
$host     = 'localhost';
$port     = '5433';
$dbname   = 'tardigsys';
$username = 'tardigsys';
$password = 'q!w@e#r$t%';

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);



    $arquivo = '/home/jlalves/retorno/backup/2025/07/18/ACC.AADGMV.SIACC2.R014916.202507180254100772';
    // $arquivo = '/home/jlalves/retorno/backup/ACC.ABPUJI.SIACC2.R203046.20261029131201464';
    $arquivo = '/home/jlalves/projetos/ACC.ABPTXP.SIACC2.R000000.202611061794017042';
    $arquivo = '/home/jlalves/projetos/ACC.ABPPNE.SIACC2.R483425.20261106230402947';

    $leitor = new RetornoBanco($pdo, $logger);

    // Lê e separa os registros
    $dados = $leitor->processar($arquivo);
    PRINT_R($dados);

    // exit;
    // Grava no PostgreSQL
    $gravacao = $leitor->gravar($dados);

    $this->logger->info('Processamento concluído');
    $this->logger->info('favorecidos gravados ' . $gravacao['favorecidos_gravados']);
    $this->logger->info('erros_encontrados ' . count($dados['erros']));

    $this->logger->info($dados['erros']);

    echo "PROCESSAMENTO CONCLUÍDO\n";
    echo "=======================\n";
    // echo "Favorecidos gravados: " . $gravacao['favorecidos_gravados'] . "\n";
    echo "Erros encontrados: " . count($dados['erros']) . "\n";
    if ($dados['erros']) {
        print_r($dados['erros']);
    }
} catch (RuntimeException $e) {
    $this->logger->error("ERRO: " . $e->getMessage());
    echo "ERRO: " . $e->getMessage() . "\n";
}
