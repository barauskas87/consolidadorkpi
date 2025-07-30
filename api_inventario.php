<?php
require_once 'config.php'; // Inclui $serverName, $connectionInfo e API_TOKEN
define('LOG_FILE', __DIR__ . '/api_log.txt');

// ===================================================================
// FUNÇÕES AUXILIARES 
// ===================================================================
function write_log($msg) { file_put_contents(LOG_FILE, date('Y-m-d H:i:s') . " - $msg\n", FILE_APPEND); }
function send_response($status, $data = []) { http_response_code(200); header('Content-Type: application/json; charset=utf-8'); echo json_encode(array_merge(['status' => $status], $data)); exit; }
function merge_datetime($data, $hora, $hora_inicio = null) { if (empty($hora) || strtoupper($hora) === 'N/A' || strtoupper($hora) === 'NÃO HÁ') return null; $data_obj = DateTime::createFromFormat('d/m/Y', $data); if (!$data_obj) return null; $parts = explode(':', $hora); $data_obj->setTime((int)($parts[0]??0), (int)($parts[1]??0), (int)($parts[2]??0)); if ($hora_inicio) { try { $inicio = new DateTime($hora_inicio); if ($data_obj < $inicio) $data_obj->modify('+1 day'); } catch (Exception $e) {} } return $data_obj->format('Y-m-d H:i:s'); }
function to_float($valor) { if (empty($valor) || strtoupper($valor) === 'N/A' || strtoupper($valor) === 'NÃO HÁ') return 0; return floatval(str_replace(['%', ','], ['', '.'], $valor)); }
function to_int($valor) { if (empty($valor) || strtoupper($valor) === 'N/A' || strtoupper($valor) === 'NÃO HÁ') return 0; return intval(str_replace([',', '.'], ['', ''], $valor)); }

// ===================================================================
// VALIDAÇÃO DA REQUISIÇÃO 
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { send_response('erro', ['mensagem' => 'Método HTTP inválido. Use POST.']); }
$headers = getallheaders();
$auth_header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
if (strpos($auth_header, 'Bearer ') !== 0) { send_response('erro', ['mensagem' => 'Token ausente/inválido.']); }
$token = substr($auth_header, 7);
if ($token !== API_TOKEN) { send_response('erro', ['mensagem' => 'Token inválido.']); }

if (!isset($_POST['kpi_data'])) { write_log("Campo 'kpi_data' ausente."); send_response('erro', ['mensagem' => "Payload JSON 'kpi_data' ausente."]); }
$json_string = $_POST['kpi_data'];
$data = json_decode($json_string, true);
if (json_last_error() !== JSON_ERROR_NONE) { send_response('erro', ['mensagem' => 'JSON inválido.']); }
$inventarios = $data['inventarios'] ?? [];

$conn = sqlsrv_connect($serverName, $connectionInfo);
if ($conn === false) { write_log("Falha na conexão com o DB: " . print_r(sqlsrv_errors(), true)); send_response('erro', ['mensagem' => 'Falha na conexão com o DB.']); }

// ===================================================================
// DEFINIÇÕES E SQL CORRIGIDO
// ===================================================================
$campos = [ 'OS','DATA','CLIENTE','FILIAL','COORDENADOR','PIV_PESSOAS_PREVISTAS','QTD_PESSOAS_ENVIADAS','TOTAL_DE_PECAS_PREVISTA','TOTAL_DE_PECAS_COLETADAS','HORARIO_INICIO_CONTAGEM','HORARIO_TERMINO_CONTAGEM','HORARIO_INICIO_DIVERGENCIA','HORARIO_TERMINO_DE_DIVERGENCIA','HORARIO_TERMINO_INVENTARIO','DURACAO_CONTAGEM','DURACAO_CONTAGEM_FORMATADO','DURACAO_DIVERGENCIA','DURACAO_INVENTARIO','TOTAL_DE_ITENS_COLETADOS','TOTAL_DE_ITENS_DIVERGENTES','TOTAL_DE_ITENS_ALTERADOS','INACURACIDADE','ACURACIDADE','INASSERTIVIDADE','ASSERTIVIDADE','APH','APH_PREVISTO','SEGMENTO','CHAVE' ];
$campos_base64 = ['AJUSTE_PDF_BASE64','CONTAGEM_CSV_BASE64'];
$map = [ 'PIV (PESSOAS PREVISTAS)' => 'PIV_PESSOAS_PREVISTAS', 'QTD PESSOAS ENVIADAS' => 'QTD_PESSOAS_ENVIADAS', 'TOTAL DE PECAS PREVISTA' => 'TOTAL_DE_PECAS_PREVISTA', 'TOTAL DE PECAS COLETADAS' => 'TOTAL_DE_PECAS_COLETADAS', 'TOTAL DE ITENS COLETADOS' => 'TOTAL_DE_ITENS_COLETADOS', 'TOTAL DE ITENS DIVERGENTES' => 'TOTAL_DE_ITENS_DIVERGENTES', 'TOTAL DE ITENS ALTERADOS' => 'TOTAL_DE_ITENS_ALTERADOS', 'HORARIO INICIO CONTAGEM' => 'HORARIO_INICIO_CONTAGEM', 'HORARIO TERMINO CONTAGEM' => 'HORARIO_TERMINO_CONTAGEM', 'HORARIO INICIO DIVERGENCIA' => 'HORARIO_INICIO_DIVERGENCIA', 'HORARIO TERMINO DE DIVERGENCIA' => 'HORARIO_TERMINO_DE_DIVERGENCIA', 'HORARIO TERMINO INVENTARIO' => 'HORARIO_TERMINO_INVENTARIO', 'DURACAO CONTAGEM' => 'DURACAO_CONTAGEM', 'DURACAO CONTAGEM (FORMATADO)' => 'DURACAO_CONTAGEM_FORMATADO', 'DURACAO DIVERGENCIA' => 'DURACAO_DIVERGENCIA', 'DURACAO INVENTARIO' => 'DURACAO_INVENTARIO', 'APH PREVISTO' => 'APH_PREVISTO' ];

//Usar $campos_sql que inclui os campos base64
$campos_sql = array_merge($campos, $campos_base64);
$sql = "INSERT INTO CONSOLIDADOR_KPI (".implode(',', $campos_sql).") VALUES (".implode(',', array_fill(0, count($campos_sql), '?')).")";
$sucessos = 0; $falhas = 0; $erros_detalhados = [];

// ===================================================================
// LOOP DE PROCESSAMENTO CORRIGIDO
// ===================================================================
foreach ($inventarios as $index => &$inv) { // Usando índice e referência
    if (empty($inv['OS'])) { continue; }
    
    // Mapeamento
    foreach ($map as $jsonKey => $dbKey) { if (isset($inv[$jsonKey])) { $inv[$dbKey] = $inv[$jsonKey]; } }

    // Lógica para receber arquivos de $_FILES
    $csv_key = "detalhes_csv_base64_{$index}";
    if (isset($_FILES[$csv_key]) && $_FILES[$csv_key]['error'] === UPLOAD_ERR_OK) {
        $inv['CONTAGEM_CSV_BASE64'] = file_get_contents($_FILES[$csv_key]['tmp_name']);
    }

    $pdf_key = "pdf_divergencia_base64_{$index}";
    if (isset($_FILES[$pdf_key]) && $_FILES[$pdf_key]['error'] === UPLOAD_ERR_OK) {
        $inv['AJUSTE_PDF_BASE64'] = file_get_contents($_FILES[$pdf_key]['tmp_name']);
    }
    
    // Tratamento de datas e números 
    $data_base = $inv['DATA'] ?? ''; $data_obj = DateTime::createFromFormat('d/m/Y', $data_base); $inv['DATA'] = $data_obj ? $data_obj->format('Y-m-d') : null;
    $inicio_contagem = merge_datetime($data_base, $inv['HORARIO_INICIO_CONTAGEM'] ?? null);
    $inv['HORARIO_INICIO_CONTAGEM'] = $inicio_contagem;
    $inv['HORARIO_TERMINO_CONTAGEM'] = merge_datetime($data_base, $inv['HORARIO_TERMINO_CONTAGEM'] ?? null, $inicio_contagem);
    $inv['HORARIO_INICIO_DIVERGENCIA'] = merge_datetime($data_base, $inv['HORARIO_INICIO_DIVERGENCIA'] ?? null, $inicio_contagem);
    $inv['HORARIO_TERMINO_DE_DIVERGENCIA'] = merge_datetime($data_base, $inv['HORARIO_TERMINO_DE_DIVERGENCIA'] ?? null, $inicio_contagem);
    $inv['HORARIO_TERMINO_INVENTARIO'] = merge_datetime($data_base, $inv['HORARIO_TERMINO_INVENTARIO'] ?? null, $inicio_contagem);
    $inv['PIV_PESSOAS_PREVISTAS'] = to_int($inv['PIV_PESSOAS_PREVISTAS'] ?? 0); $inv['QTD_PESSOAS_ENVIADAS'] = to_int($inv['QTD_PESSOAS_ENVIADAS'] ?? 0); $inv['TOTAL_DE_PECAS_PREVISTA'] = to_int($inv['TOTAL_DE_PECAS_PREVISTA'] ?? 0); $inv['TOTAL_DE_PECAS_COLETADAS'] = to_int($inv['TOTAL_DE_PECAS_COLETADAS'] ?? 0); $inv['TOTAL_DE_ITENS_COLETADOS'] = to_int($inv['TOTAL_DE_ITENS_COLETADOS'] ?? 0); $inv['TOTAL_DE_ITENS_DIVERGENTES'] = to_int($inv['TOTAL_DE_ITENS_DIVERGENTES'] ?? 0); $inv['TOTAL_DE_ITENS_ALTERADOS'] = to_int($inv['TOTAL_DE_ITENS_ALTERADOS'] ?? 0); $inv['INACURACIDADE'] = to_float($inv['INACURACIDADE'] ?? 0); $inv['ACURACIDADE'] = to_float($inv['ACURACIDADE'] ?? 0); $inv['INASSERTIVIDADE'] = to_float($inv['INASSERTIVIDADE'] ?? 0); $inv['ASSERTIVIDADE'] = to_float($inv['ASSERTIVIDADE'] ?? 0); $inv['APH'] = to_float($inv['APH'] ?? 0); $inv['APH_PREVISTO'] = to_float($inv['APH_PREVISTO'] ?? 0);

    // Preparar parâmetros com tipos de dados explícitos para campos grandes
    $params = [];
    foreach ($campos_sql as $campo) {
        $valor = $inv[$campo] ?? null;
        if (in_array($campo, $campos_base64)) {
            $params[] = [ $valor, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_CHAR), SQLSRV_SQLTYPE_VARCHAR('max') ];
        } else {
            $params[] = $valor;
        }
    }
    
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $falhas++;
        $error_message = "Falha ao inserir OS {$inv['OS']}: " . print_r(sqlsrv_errors(), true);
        write_log($error_message);
        $erros_detalhados[] = "OS {$inv['OS']}: Erro de banco de dados.";
    } else {
        $sucessos++;
        sqlsrv_free_stmt($stmt);
    }
}
sqlsrv_close($conn);

// ===================================================================
// RESPOSTA FINAL
// ===================================================================
send_response('sucesso', [
    'mensagem' => 'Dados processados pelo servidor.',
    'registros_recebidos' => count($inventarios),
    'registros_inseridos' => $sucessos,
    'registros_falhos' => $falhas,
    'erros' => $erros_detalhados
]);
?>
