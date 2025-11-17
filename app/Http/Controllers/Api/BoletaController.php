<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesPdfGeneration;
use App\Http\Controllers\Traits\ValidatesCompanyAccess;
use App\Http\Requests\Boleta\CreateDailySummaryRequest;
use App\Http\Requests\Boleta\GetBoletasPendingRequest;
use App\Http\Requests\Boleta\IndexBoletaRequest;
use App\Http\Requests\Boleta\StoreBoletaRequest;
use App\Models\Boleta;
use App\Models\DailySummary;
use App\Services\DocumentService;
use App\Services\FileService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BoletaController extends Controller
{
    use HandlesPdfGeneration, ValidatesCompanyAccess;

    protected DocumentService $documentService;
    protected FileService $fileService;

    public function __construct(DocumentService $documentService, FileService $fileService)
    {
        $this->documentService = $documentService;
        $this->fileService = $fileService;
    }

    /**
     * @OA\Get(
     *     path="/api/v1/boletas",
     *     summary="Listar todas las boletas",
     *     description="Obtiene un listado paginado de boletas con filtros opcionales",
     *     tags={"Boletas"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Número de página",
     *         required=false,
     *         @OA\Schema(type="integer", default=1, example=1)
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Resultados por página",
     *         required=false,
     *         @OA\Schema(type="integer", default=15, example=15)
     *     ),
     *     @OA\Parameter(
     *         name="company_id",
     *         in="query",
     *         description="Filtrar por empresa",
     *         required=false,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de boletas obtenida exitosamente",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="numero_completo", type="string", example="B001-000001"),
     *                     @OA\Property(property="fecha_emision", type="string", format="date", example="2025-11-07"),
     *                     @OA\Property(property="mto_imp_venta", type="number", format="float", example=118.00),
     *                     @OA\Property(property="estado_sunat", type="string", example="ACEPTADO")
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="pagination",
     *                 type="object",
     *                 @OA\Property(property="total", type="integer", example=50),
     *                 @OA\Property(property="per_page", type="integer", example=15),
     *                 @OA\Property(property="current_page", type="integer", example=1)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="No autenticado"
     *     )
     * )
     * 
     * Listar boletas con filtros
     */
    public function index(IndexBoletaRequest $request): JsonResponse
    {
        try {
            $query = Boleta::with(['company', 'branch', 'client']);
            $this->applyFilters($query, $request);
            
            $perPage = $request->get('per_page', 15);
            $boletas = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $boletas->items(),
                'pagination' => $this->getPaginationData($boletas)
            ]);

        } catch (Exception $e) {
            return $this->errorResponse('Error al listar boletas', $e);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/boletas",
     *     summary="Crear una nueva boleta",
     *     description="Crea una nueva boleta de venta electrónica",
     *     tags={"Boletas"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Datos de la boleta a crear",
     *         @OA\JsonContent(
     *             required={"company_id", "branch_id", "client_id", "serie", "fecha_emision", "moneda", "items"},
     *             @OA\Property(property="company_id", type="integer", example=1, description="ID de la empresa"),
     *             @OA\Property(property="branch_id", type="integer", example=1, description="ID de la sucursal"),
     *             @OA\Property(property="client_id", type="integer", example=1, description="ID del cliente"),
     *             @OA\Property(property="serie", type="string", example="B001", description="Serie de la boleta"),
     *             @OA\Property(property="fecha_emision", type="string", format="date", example="2025-11-17", description="Fecha de emisión"),
     *             @OA\Property(property="moneda", type="string", example="PEN", description="Código de moneda (PEN/USD)"),
     *             @OA\Property(property="tipo_operacion", type="string", example="0101", description="Tipo de operación SUNAT"),
     *             @OA\Property(
     *                 property="items",
     *                 type="array",
     *                 description="Items de la boleta",
     *                 @OA\Items(
     *                     @OA\Property(property="codigo", type="string", example="PROD001"),
     *                     @OA\Property(property="descripcion", type="string", example="Producto de prueba"),
     *                     @OA\Property(property="cantidad", type="number", example=2),
     *                     @OA\Property(property="unidad_medida", type="string", example="NIU"),
     *                     @OA\Property(property="precio_unitario", type="number", example=50.00),
     *                     @OA\Property(property="tipo_afectacion_igv", type="string", example="10")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Boleta creada exitosamente",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Boleta creada exitosamente"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=2),
     *                 @OA\Property(property="numero_completo", type="string", example="B001-000002"),
     *                 @OA\Property(property="fecha_emision", type="string", example="2025-11-17"),
     *                 @OA\Property(property="mto_imp_venta", type="number", example=118.00)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Error de validación"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="No autenticado"
     *     )
     * )
     * 
     * Crear nueva boleta
     */
    public function store(StoreBoletaRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $boleta = $this->documentService->createBoleta($validated);

            return response()->json([
                'success' => true,
                'data' => $boleta->load(['company', 'branch', 'client']),
                'message' => 'Boleta creada correctamente'
            ], 201);

        } catch (Exception $e) {
            return $this->errorResponse('Error al crear la boleta', $e);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/boletas/{id}",
     *     summary="Obtener detalle de una boleta",
     *     description="Obtiene la información detallada de una boleta específica",
     *     tags={"Boletas"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de la boleta",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Detalle de la boleta",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="numero_completo", type="string", example="B001-000001"),
     *                 @OA\Property(property="serie", type="string", example="B001"),
     *                 @OA\Property(property="correlativo", type="string", example="000001"),
     *                 @OA\Property(property="fecha_emision", type="string", format="date", example="2025-11-17"),
     *                 @OA\Property(property="moneda", type="string", example="PEN"),
     *                 @OA\Property(property="mto_imp_venta", type="number", example=118.00),
     *                 @OA\Property(property="estado_sunat", type="string", example="ACEPTADO"),
     *                 @OA\Property(property="xml_path", type="string", example="boletas/xml/17112025/B001-000001.xml"),
     *                 @OA\Property(property="cdr_path", type="string", example="boletas/cdr/17112025/R-B001-000001.zip"),
     *                 @OA\Property(property="pdf_path", type="string", example="boletas/pdf/17112025/B001-000001.pdf"),
     *                 @OA\Property(
     *                     property="company",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="razon_social", type="string", example="MI EMPRESA SAC")
     *                 ),
     *                 @OA\Property(
     *                     property="client",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="nombre", type="string", example="CLIENTE PRUEBA")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Boleta no encontrada"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="No autenticado"
     *     )
     * )
     * 
     * Obtener boleta específica
     */
    public function show(string $id, Request $request): JsonResponse
    {
        try {
            $boleta = Boleta::with(['company', 'branch', 'client'])->findOrFail($id);
            
            // Validar acceso a la empresa
            if ($accessError = $this->validateCompanyAccess($boleta, $request)) {
                return $accessError;
            }
            
            return response()->json([
                'success' => true,
                'data' => $boleta
            ]);
        } catch (Exception $e) {
            return $this->notFoundResponse('Boleta no encontrada');
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/boletas/{id}/send-sunat",
     *     summary="Enviar boleta a SUNAT",
     *     description="Envía la boleta a SUNAT para su validación y genera el XML y CDR automáticamente",
     *     tags={"Boletas"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de la boleta",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Boleta enviada exitosamente a SUNAT",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Boleta enviada exitosamente a SUNAT"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="numero_completo", type="string", example="B001-000001"),
     *                 @OA\Property(property="estado_sunat", type="string", example="ACEPTADO"),
     *                 @OA\Property(property="xml_path", type="string", example="boletas/xml/17112025/B001-000001.xml"),
     *                 @OA\Property(property="cdr_path", type="string", example="boletas/cdr/17112025/R-B001-000001.zip"),
     *                 @OA\Property(property="codigo_hash", type="string", example="abc123...")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Boleta ya fue enviada a SUNAT"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Boleta no encontrada"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="No autenticado"
     *     )
     * )
     * 
     * Enviar boleta a SUNAT
     */
    public function sendToSunat(string $id): JsonResponse
    {
        try {
            $boleta = Boleta::with(['company', 'branch', 'client'])->findOrFail($id);
            
            if ($boleta->estado_sunat === 'ACEPTADO') {
                return response()->json([
                    'success' => false,
                    'message' => 'La boleta ya fue aceptada por SUNAT'
                ], 400);
            }

            $result = $this->documentService->sendToSunat($boleta, 'boleta');
            
            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['document']->load(['company', 'branch', 'client']),
                    'message' => 'Boleta enviada exitosamente a SUNAT'
                ]);
            }

            return $this->handleSunatError($result);

        } catch (Exception $e) {
            return $this->errorResponse('Error interno al enviar a SUNAT', $e);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/boletas/{id}/download-xml",
     *     summary="Descargar XML de la boleta",
     *     description="Descarga el archivo XML de la boleta generado por SUNAT",
     *     tags={"Boletas"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de la boleta",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Archivo XML descargado",
     *         @OA\MediaType(
     *             mediaType="application/xml",
     *             @OA\Schema(type="string", format="binary")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="XML no encontrado"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="No autenticado"
     *     )
     * )
     * 
     * Descargar XML de boleta
     */
    public function downloadXml(string $id): Response
    {
        try {
            $boleta = Boleta::findOrFail($id);
            
            if (!$this->fileService->fileExists($boleta->xml_path)) {
                return $this->notFoundResponse('XML no encontrado');
            }

            return $this->fileService->downloadFile(
                $boleta->xml_path,
                $boleta->numero_completo . '.xml',
                ['Content-Type' => 'application/xml']
            );

        } catch (Exception $e) {
            return $this->errorResponse('Error al descargar XML', $e);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/boletas/{id}/download-cdr",
     *     summary="Descargar CDR de la boleta",
     *     description="Descarga el Comprobante de Recepción (CDR) generado por SUNAT en formato ZIP",
     *     tags={"Boletas"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de la boleta",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Archivo CDR (ZIP) descargado",
     *         @OA\MediaType(
     *             mediaType="application/zip",
     *             @OA\Schema(type="string", format="binary")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="CDR no encontrado"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="No autenticado"
     *     )
     * )
     * 
     * Descargar CDR de boleta
     */
    public function downloadCdr(string $id): Response
    {
        try {
            $boleta = Boleta::findOrFail($id);
            
            if (!$this->fileService->fileExists($boleta->cdr_path)) {
                return $this->notFoundResponse('CDR no encontrado');
            }

            return $this->fileService->downloadFile(
                $boleta->cdr_path,
                'R-' . $boleta->numero_completo . '.zip',
                ['Content-Type' => 'application/zip']
            );

        } catch (Exception $e) {
            return $this->errorResponse('Error al descargar CDR', $e);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/boletas/{id}/download-pdf",
     *     summary="Descargar PDF de la boleta",
     *     description="Descarga el archivo PDF de la boleta previamente generado",
     *     tags={"Boletas"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de la boleta",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Archivo PDF descargado",
     *         @OA\MediaType(
     *             mediaType="application/pdf",
     *             @OA\Schema(type="string", format="binary")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="PDF no encontrado"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="No autenticado"
     *     )
     * )
     * 
     * Descargar PDF de boleta
     */
    public function downloadPdf(string $id, Request $request): Response
    {
        try {
            $boleta = Boleta::findOrFail($id);
            return $this->downloadDocumentPdf($boleta, $request);
        } catch (Exception $e) {
            return $this->errorResponse('Error al descargar PDF', $e);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/boletas/{id}/generate-pdf",
     *     summary="Generar PDF de la boleta",
     *     description="Genera el archivo PDF de la boleta con el formato configurado",
     *     tags={"Boletas"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de la boleta",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Parameter(
     *         name="format",
     *         in="query",
     *         description="Formato del PDF (A4, A5, TICKET)",
     *         required=false,
     *         @OA\Schema(type="string", enum={"A4", "A5", "TICKET"}, default="A4", example="A4")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="PDF generado exitosamente",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="PDF generado exitosamente"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="numero_completo", type="string", example="B001-000001"),
     *                 @OA\Property(property="pdf_path", type="string", example="boletas/pdf/17112025/B001-000001.pdf")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Boleta no encontrada"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="No autenticado"
     *     )
     * )
     * 
     * Generar PDF de boleta
     */
    public function generatePdf(string $id, Request $request): Response
    {
        try {
            $boleta = Boleta::with(['company', 'branch', 'client'])->findOrFail($id);
            return $this->generateDocumentPdf($boleta, 'boleta', $request);
        } catch (Exception $e) {
            return $this->errorResponse('Error al generar PDF', $e);
        }
    }

    /**
     * Crear resumen diario desde fecha
     */
    public function createDailySummaryFromDate(CreateDailySummaryRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $summary = $this->documentService->createSummaryFromBoletas($validated);

            return response()->json([
                'success' => true,
                'data' => $summary->load(['company', 'branch', 'boletas']),
                'message' => 'Resumen diario creado correctamente'
            ], 201);

        } catch (Exception $e) {
            return $this->errorResponse('Error al crear resumen diario', $e);
        }
    }

    /**
     * Enviar resumen a SUNAT
     */
    public function sendSummaryToSunat(string $summaryId): JsonResponse
    {
        try {
            $summary = DailySummary::with(['company', 'branch', 'boletas'])->findOrFail($summaryId);

            if ($summary->estado_sunat === 'ACEPTADO') {
                return response()->json([
                    'success' => false,
                    'message' => 'El resumen ya fue aceptado por SUNAT'
                ], 400);
            }

            $result = $this->documentService->sendDailySummaryToSunat($summary);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['document']->load(['company', 'branch', 'boletas']),
                    'ticket' => $result['ticket'],
                    'message' => 'Resumen enviado correctamente a SUNAT'
                ]);
            }

            return response()->json([
                'success' => false,
                'data' => $result['document']->load(['company', 'branch', 'boletas']),
                'message' => 'Error al enviar resumen a SUNAT',
                'error' => $result['error']
            ], 400);

        } catch (Exception $e) {
            return $this->errorResponse('Error interno al enviar resumen', $e);
        }
    }

    /**
     * Consultar estado de resumen
     */
    public function checkSummaryStatus(string $summaryId): JsonResponse
    {
        try {
            $summary = DailySummary::with(['company', 'branch', 'boletas'])->findOrFail($summaryId);
            $result = $this->documentService->checkSummaryStatus($summary);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['document']->load(['company', 'branch', 'boletas']),
                    'message' => 'Estado del resumen consultado correctamente'
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Error al consultar estado: ' . ($result['error'] ?? 'Error desconocido')
            ], 400);

        } catch (Exception $e) {
            return $this->errorResponse('Error al consultar estado del resumen', $e);
        }
    }

    /**
     * Obtener boletas pendientes para resumen
     */
    public function getBoletsasPendingForSummary(GetBoletasPendingRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $boletas = $this->getPendingBoletas($validated);

            return response()->json([
                'success' => true,
                'data' => $boletas,
                'total' => $boletas->count(),
                'message' => 'Boletas pendientes obtenidas correctamente'
            ]);

        } catch (Exception $e) {
            return $this->errorResponse('Error al obtener boletas pendientes', $e);
        }
    }

    /**
     * Aplicar filtros a la consulta
     */
    private function applyFilters($query, Request $request): void
    {
        $filters = [
            'company_id' => 'where',
            'branch_id' => 'where',
            'estado_sunat' => 'where',
            'fecha_desde' => 'whereDate|>=',
            'fecha_hasta' => 'whereDate|<='
        ];

        foreach ($filters as $field => $operation) {
            if ($request->has($field)) {
                $parts = explode('|', $operation);
                $method = $parts[0];
                $operator = $parts[1] ?? null;

                if ($operator) {
                    $query->$method('fecha_emision', $operator, $request->$field);
                } else {
                    $query->$method($field, $request->$field);
                }
            }
        }
    }

    /**
     * Obtener boletas pendientes
     */
    private function getPendingBoletas(array $filters)
    {
        return Boleta::with(['company', 'branch', 'client'])
            ->where('company_id', $filters['company_id'])
            ->where('branch_id', $filters['branch_id'])
            ->whereDate('fecha_emision', $filters['fecha_emision'])
            ->where('estado_sunat', 'PENDIENTE')
            ->whereNull('daily_summary_id')
            ->get();
    }

    /**
     * Manejar error de SUNAT
     */
    private function handleSunatError(array $result): JsonResponse
    {
        $error = $result['error'];
        $errorCode = 'UNKNOWN';
        $errorMessage = 'Error desconocido';

        if (is_object($error)) {
            $errorCode = method_exists($error, 'getCode') ? $error->getCode() : ($error->code ?? $errorCode);
            $errorMessage = method_exists($error, 'getMessage') ? $error->getMessage() : ($error->message ?? $errorMessage);
        }

        return response()->json([
            'success' => false,
            'data' => $result['document'],
            'message' => 'Error al enviar a SUNAT: ' . $errorMessage,
            'error_code' => $errorCode
        ], 400);
    }

    /**
     * Obtener datos de paginación
     */
    private function getPaginationData($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * Respuesta de error estandarizada
     */
    private function errorResponse(string $message, Exception $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message . ': ' . $e->getMessage()
        ], 500);
    }

    /**
     * Respuesta de no encontrado
     */
    private function notFoundResponse(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message
        ], 404);
    }
}