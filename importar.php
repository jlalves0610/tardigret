<?php

require_once __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
// use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

// Criar diretório de logs, se necessário
$diretorioLog = __DIR__ . '/logs';

if (!is_dir($diretorioLog)) {
    mkdir($diretorioLog, 0755, true);
}

// Instanciar logger
$logger = new Logger('RETORNO');

$handler = new RotatingFileHandler($diretorioLog . '/cnab.log', 30,  Logger::DEBUG);
// Formatar logs
$formatter = new LineFormatter("[%datetime%] %level_name%: %message% %context% %extra%\n", 'Y-m-d H:i:s',    true,    true);
$formatter->includeStacktraces(false);

$handler->setFormatter($formatter);
$logger->pushHandler($handler);

$logger->info('Iniciando importação CNAB Caixa');
