<?php
require_once 'config.php';
define('LOG_FILE', __DIR__ . '/api_log.txt');

function write_log($msg) {
    file_put_contents(LOG_FILE, date('Y-m-d H:i:s') . " - $msg\n", FILE_APPEND);
}

function send_response($status, $mensagem = '') {
    header('Content-Type: application/json');
    echo json_encode(['status' => $status, 'mensagem' => $mensagem]);
    exit;
}

function merge_datetime($data, $hora, $hora_inicio = null) {
    if (empty($hora) || strtoupper($hora) === 'N/A' || strtoupper($hora) === 'NÃO HÁ') return null;
    $data_obj = DateTime::createFromFormat('d/m/Y', $data);
    if (!$data_obj) return null;
    $parts = explode(':', $hora);
    $h = (int)($parts[0] ?? 0);
    $m = (int)($parts[1] ?? 0);
    $s = (int)($parts[2] ?? 0);
    $data_obj->setTime($h, $m, $s);
    if ($hora_inicio) {
        try {
            $inicio = new DateTime($hora_inicio);
            if ($data_obj < $inicio) $data_obj->modify('+1 day');
        } catch (Exception $e) { /* Ignora */ }
    }
    return $data_obj->format('Y-m-d H:i:s');
}

function to_float($valor) {
    if (empty($valor) || strtoupper($valor) === 'N/A' || strtoupper($valor) === 'NÃO HÁ') return 0;
    return floatval(str_replace(['%', ','], ['', '.'], $valor));
}

function to_int($valor) {
    if (empty($valor) || strtoupper($valor) === 'N/A' || strtoupper($valor) === 'NÃO HÁ') return 0;
    return intval(str_replace([',', '.'], ['', ''], $valor));
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    write_log("Método inválido: " . $_SERVER['REQUEST_METHOD']);
    send_response('erro', 'Método HTTP inválido. Use POST.');
}

$headers = getallheaders();
if (!isset($headers['Authorization']) || strpos($headers['Authorization'], 'Bearer ') !== 0) {
    write_log("Token ausente/inválido");
    send_response('erro', 'Token inválido.');
}
$token = substr($headers['Authorization'], 7);
if ($token !== API_TOKEN) {
    write_log("Token inválido: $token");
    send_response('erro', 'Token inválido.');
}

// ########## MUDANÇA 1: De `php://input` para `$_POST` e `$_FILES` ##########
// Em vez de ler o corpo bruto, agora esperamos um campo de formulário chamado 'kpi_data' com o JSON
// e arquivos separados que serão tratados pela superglobal $_FILES.

if (!isset($_POST['kpi_data'])) {
    write_log("Erro: campo 'kpi_data' ausente na requisição multipart.");
    send_response('erro', "Payload JSON 'kpi_data' ausente.");
}

$input_json = $_POST['kpi_data'];
write_log("JSON recebido via $_POST[kpi_data]: $input_json");

$data = json_decode($input_json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    write_log("Erro de decodificação JSON: " . json_last_error_msg());
    send_response('erro', 'JSON inválido em kpi_data.');
}
// ########## FIM DA MUDANÇA 1 ##########


if (isset($data['test'])) {
    write_log("Requisição de teste recebida, ignorando inserção.");
    send_response('sucesso','Conexão OK');
}

$inventarios = isset($data['inventarios']) && is_array($data['inventarios']) ? $data['inventarios'] : [$data];

$campos = [
    'OS','DATA','CLIENTE','FILIAL','COORDENADOR',
    'PIV_PESSOAS_PREVISTAS','QTD_PESSOAS_ENVIADAS',
    'TOTAL_DE_PECAS_PREVISTA','TOTAL_DE_PECAS_COLETADAS',
    'HORARIO_INICIO_CONTAGEM','HORARIO_TERMINO_CONTAGEM',
    'HORARIO_INICIO_DIVERGENCIA','HORARIO_TERMINO_DE_DIVERGENCIA',
    'HORARIO_TERMINO_INVENTARIO','DURACAO_CONTAGEM',
    'DURACAO_CONTAGEM_FORMATADO','DURACAO_DIVERGENCIA',
    'DURACAO_INVENTARIO','TOTAL_DE_ITENS_COLETADOS',
    'TOTAL_DE_ITENS_DIVERGENTES','TOTAL_DE_ITENS_ALTERADOS',
    'INACURACIDADE','ACURACIDADE','INASSERTIVIDADE','ASSERTIVIDADE',
    'APH','APH_PREVISTO','SEGMENTO','CHAVE'
];
$campos_base64 = ['AJUSTE_PDF_BASE64','CONTAGEM_CSV_BASE64'];

$map = [
    'PIV (PESSOAS PREVISTAS)' => 'PIV_PESSOAS_PREVISTAS',
    'QTD PESSOAS ENVIADAS' => 'QTD_PESSOAS_ENVIADAS',
    'TOTAL DE PECAS PREVISTA' => 'TOTAL_DE_PECAS_PREVISTA',
    'TOTAL DE PECAS COLETADAS' => 'TOTAL_DE_PECAS_COLETADAS',
    'TOTAL DE ITENS COLETADOS' => 'TOTAL_DE_ITENS_COLETADOS',
    'TOTAL DE ITENS DIVERGENTES' => 'TOTAL_DE_ITENS_DIVERGENTES',
    'TOTAL DE ITENS ALTERADOS' => 'TOTAL_DE_ITENS_ALTERADOS',
    'HORARIO INICIO CONTAGEM' => 'HORARIO_INICIO_CONTAGEM',
    'HORARIO TERMINO CONTAGEM' => 'HORARIO_TERMINO_CONTAGEM',
    'HORARIO INICIO DIVERGENCIA' => 'HORARIO_INICIO_DIVERGENCIA',
    'HORARIO TERMINO DE DIVERGENCIA' => 'HORARIO_TERMINO_DE_DIVERGENCIA',
    'HORARIO TERMINO INVENTARIO' => 'HORARIO_TERMINO_INVENTARIO',
    'DURACAO CONTAGEM' => 'DURACAO_CONTAGEM',
    'DURACAO CONTAGEM (FORMATADO)' => 'DURACAO_CONTAGEM_FORMATADO',
    'DURACAO DIVERGENCIA' => 'DURACAO_DIVERGENCIA',
    'DURACAO INVENTARIO' => 'DURACAO_INVENTARIO',
    'APH PREVISTO' => 'APH_PREVISTO',
    // O mapeamento dos campos base64 não é mais necessário aqui,
    // pois vamos lidar com os arquivos diretamente.
];

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_errno) send_response('erro','Falha conexão DB.');

$campos_sql = array_merge($campos,$campos_base64);
$placeholders = implode(',', array_fill(0,count($campos_sql),'?'));
$sql = "INSERT INTO inventario_os_teste (".implode(',',$campos_sql).") VALUES ($placeholders)";
$stmt = $mysqli->prepare($sql);
if (!$stmt) send_response('erro','Erro preparar query: '.$mysqli->error);

$tipos = 'sssssiiiisssssssssiiiddddddssss';

foreach ($inventarios as $i => $inv) {

    if (empty($inv['OS'])) {
        write_log("Inventário ignorado: OS vazio.");
        continue;
    }

    foreach ($map as $jsonKey => $dbKey) {
        if (isset($inv[$jsonKey])) {
            $inv[$dbKey] = $inv[$jsonKey];
        }
    }
    
    // ########## MUDANÇA 2: Processar arquivos de `$_FILES` ##########
    // Em vez de esperar dados Base64 no JSON, procuramos por arquivos enviados.
    // O nome do arquivo no upload (ex: 'detalhes_csv_0', 'pdf_divergencia_0') será
    // construído dinamicamente pelo cliente Python.
    
    $indice_lote = $i; // Usamos o índice do inventário no lote
    
    // Processa o arquivo CSV dos detalhes dos itens
    $nome_arquivo_csv = 'detalhes_csv_' . $indice_lote;
    if (isset($_FILES[$nome_arquivo_csv]) && $_FILES[$nome_arquivo_csv]['error'] === UPLOAD_ERR_OK) {
        $caminho_tmp = $_FILES[$nome_arquivo_csv]['tmp_name'];
        $conteudo_csv = file_get_contents($caminho_tmp);
        $inv['CONTAGEM_CSV_BASE64'] = base64_encode($conteudo_csv);
        write_log("Arquivo CSV para OS {$inv['OS']} processado com sucesso.");
    } else {
        $inv['CONTAGEM_CSV_BASE64'] = null; // Garante que o campo seja nulo se o arquivo não for enviado.
    }
    
    // Processa o arquivo PDF de divergência
    $nome_arquivo_pdf = 'pdf_divergencia_' . $indice_lote;
    if (isset($_FILES[$nome_arquivo_pdf]) && $_FILES[$nome_arquivo_pdf]['error'] === UPLOAD_ERR_OK) {
        $caminho_tmp_pdf = $_FILES[$nome_arquivo_pdf]['tmp_name'];
        $conteudo_pdf = file_get_contents($caminho_tmp_pdf);
        $inv['AJUSTE_PDF_BASE64'] = base64_encode($conteudo_pdf);
        write_log("Arquivo PDF para OS {$inv['OS']} processado com sucesso.");
    } else {
        $inv['AJUSTE_PDF_BASE64'] = null;
    }
    // ########## FIM DA MUDANÇA 2 ##########

    $data_base = $inv['DATA'] ?? '';
    $data_obj = DateTime::createFromFormat('d/m/Y', $data_base);
    $inv['DATA'] = $data_obj ? $data_obj->format('Y-m-d') : null;

    $inicio_contagem = merge_datetime($data_base, $inv['HORARIO_INICIO_CONTAGEM'] ?? null);
    $inv['HORARIO_INICIO_CONTAGEM'] = $inicio_contagem;
    $inv['HORARIO_TERMINO_CONTAGEM'] = merge_datetime($data_base, $inv['HORARIO_TERMINO_CONTAGEM'] ?? null, $inicio_contagem);
    $inv['HORARIO_INICIO_DIVERGENCIA'] = merge_datetime($data_base, $inv['HORARIO_INICIO_DIVERGENCIA'] ?? null, $inicio_contagem);
    $inv['HORARIO_TERMINO_DE_DIVERGENCIA'] = merge_datetime($data_base, $inv['HORARIO_TERMINO_DE_DIVERGENCIA'] ?? null, $inicio_contagem);
    $inv['HORARIO_TERMINO_INVENTARIO'] = merge_datetime($data_base, $inv['HORARIO_TERMINO_INVENTARIO'] ?? null, $inicio_contagem);


    $inv['PIV_PESSOAS_PREVISTAS'] = to_int($inv['PIV_PESSOAS_PREVISTAS'] ?? 0);
    $inv['QTD_PESSOAS_ENVIADAS'] = to_int($inv['QTD_PESSOAS_ENVIADAS'] ?? 0);
    $inv['TOTAL_DE_PECAS_PREVISTA'] = to_int($inv['TOTAL_DE_PECAS_PREVISTA'] ?? 0);
    $inv['TOTAL_DE_PECAS_COLETADAS'] = to_int($inv['TOTAL_DE_PECAS_COLETADAS'] ?? 0);
    $inv['TOTAL_DE_ITENS_COLETADOS'] = to_int($inv['TOTAL_DE_ITENS_COLETADOS'] ?? 0);
    $inv['TOTAL_DE_ITENS_DIVERGENTES'] = to_int($inv['TOTAL_DE_ITENS_DIVERGENTES'] ?? 0);
    $inv['TOTAL_DE_ITENS_ALTERADOS'] = to_int($inv['TOTAL_DE_ITENS_ALTERADOS'] ?? 0);
    $inv['INACURACIDADE'] = to_float($inv['INACURACIDADE'] ?? 0);
    $inv['ACURACIDADE'] = to_float($inv['ACURACIDADE'] ?? 0);
    $inv['INASSERTIVIDADE'] = to_float($inv['INASSERTIVIDADE'] ?? 0);
    $inv['ASSERTIVIDADE'] = to_float($inv['ASSERTIVIDADE'] ?? 0);
    $inv['APH'] = to_float($inv['APH'] ?? 0);
    $inv['APH_PREVISTO'] = to_float($inv['APH_PREVISTO'] ?? 0);

    $valores = [];
    foreach ($campos_sql as $campo) {
        $valores[] = $inv[$campo] ?? null;
    }

    $stmt->bind_param($tipos, ...$valores);
    
    if (!$stmt->execute()) {
        write_log("Falha ao inserir OS {$inv['OS']}: " . $stmt->error);
    } else {
        write_log("Sucesso ao inserir OS {$inv['OS']}.");
    }
}

$stmt->close();
$mysqli->close();

send_response('sucesso', 'Dados processados.');
?>
