<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesPdfGeneration;
use App\Services\DocumentService;
use App\Services\FileService;
use App\Models\CreditNote;
use App\Http\Requests\IndexCreditNoteRequest;
use App\Http\Requests\StoreCreditNoteRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *     name="Notas de Crédito",
 *     description="Gestión de notas de crédito electrónicas. Se emiten para anular o corregir facturas/boletas."
 * )
 */
class CreditNoteController extends Controller
{
    use HandlesPdfGeneration;
    
    protected $documentService;
    protected $fileService;

    public function __construct(DocumentService $documentService, FileService $fileService)
    {
        $this->documentService = $documentService;
        $this->fileService = $fileService;
    }

    /**
     * @OA\Get(
     *     path="/api/v1/credit-notes",
     *     summary="Listar notas de crédito",
     *     description="Obtiene un listado paginado de notas de crédito con filtros",
     *     tags={"Notas de Crédito"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="company_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="branch_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="estado_sunat", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="tipo_doc_afectado", in="query", @OA\Schema(type="string", description="01=Factura, 03=Boleta")),
     *     @OA\Parameter(name="fecha_desde", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="fecha_hasta", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15)),
     *     @OA\Response(response=200, description="Listado de notas de crédito"),
     *     @OA\Response(response=401, description="No autenticado")
     * )
     */
    public function index(IndexCreditNoteRequest $request): JsonResponse
    {
        try {
            $query = CreditNote::with(['company', 'branch', 'client']);

            // Filtros
            if ($request->has('company_id')) {
                $query->where('company_id', $request->company_id);
            }

            if ($request->has('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }

            if ($request->has('estado_sunat')) {
                $query->where('estado_sunat', $request->estado_sunat);
            }

            if ($request->has('tipo_doc_afectado')) {
                $query->where('tipo_doc_afectado', $request->tipo_doc_afectado);
            }

            if ($request->has('fecha_desde') && $request->has('fecha_hasta')) {
                $query->whereBetween('fecha_emision', [
                    $request->fecha_desde,
                    $request->fecha_hasta
                ]);
            }

            // Paginación
            $perPage = $request->get('per_page', 15);
            $creditNotes = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $creditNotes,
                'message' => 'Notas de crédito obtenidas correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las notas de crédito',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/credit-notes",
     *     summary="Crear nota de crédito",
     *     description="Crea una nueva nota de crédito para anular o corregir una factura o boleta",
     *     tags={"Notas de Crédito"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"company_id", "branch_id", "client_id", "serie", "tipo_doc_afectado", "num_doc_afectado", "tipo_nota_credito", "motivo_nota", "items"},
     *             @OA\Property(property="company_id", type="integer", example=1),
     *             @OA\Property(property="branch_id", type="integer", example=1),
     *             @OA\Property(property="client_id", type="integer", example=1),
     *             @OA\Property(property="serie", type="string", example="FC01", description="Serie para nota de crédito"),
     *             @OA\Property(property="tipo_doc_afectado", type="string", example="01", description="01=Factura, 03=Boleta"),
     *             @OA\Property(property="num_doc_afectado", type="string", example="F001-000001", description="Serie-Número del documento a afectar"),
     *             @OA\Property(property="tipo_nota_credito", type="string", example="01", description="Código de motivo SUNAT"),
     *             @OA\Property(property="motivo_nota", type="string", example="Anulación de la operación"),
     *             @OA\Property(property="moneda", type="string", example="PEN", description="PEN o USD"),
     *             @OA\Property(
     *                 property="items",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="descripcion", type="string", example="Producto 1"),
     *                     @OA\Property(property="cantidad", type="number", example=1),
     *                     @OA\Property(property="precio_unitario", type="number", example=100.00)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Nota de crédito creada",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Nota de crédito creada correctamente"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Errores de validación"),
     *     @OA\Response(response=401, description="No autenticado")
     * )
     */
    public function store(StoreCreditNoteRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            // Crear la nota de crédito
            $creditNote = $this->documentService->createCreditNote($validated);

            return response()->json([
                'success' => true,
                'data' => $creditNote->load(['company', 'branch', 'client']),
                'message' => 'Nota de crédito creada correctamente'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la nota de crédito',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $creditNote = CreditNote::with(['company', 'branch', 'client'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $creditNote,
                'message' => 'Nota de crédito obtenida correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Nota de crédito no encontrada',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/credit-notes/{id}/send-sunat",
     *     summary="Enviar nota de crédito a SUNAT",
     *     description="Envía la nota de crédito a SUNAT para su validación. Genera XML y recibe CDR.",
     *     tags={"Notas de Crédito"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de la nota de crédito",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Nota de crédito enviada exitosamente",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Nota de crédito enviada a SUNAT exitosamente"),
     *             @OA\Property(property="estado_sunat", type="string", example="ACEPTADO"),
     *             @OA\Property(property="xml_path", type="string", example="notas_credito/xml/17112025/FC01-000001.xml"),
     *             @OA\Property(property="cdr_path", type="string", example="notas_credito/cdr/17112025/R-FC01-000001.zip")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Nota de crédito no encontrada"),
     *     @OA\Response(response=401, description="No autenticado")
     * )
     */
    public function sendToSunat($id): JsonResponse
    {
        try {
            $creditNote = CreditNote::with(['company', 'branch', 'client'])->findOrFail($id);

            if ($creditNote->estado_sunat === 'ACEPTADO') {
                return response()->json([
                    'success' => false,
                    'message' => 'La nota de crédito ya fue enviada y aceptada por SUNAT'
                ], 400);
            }

            $result = $this->documentService->sendToSunat($creditNote, 'credit_note');

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['document'],
                    'message' => 'Nota de crédito enviada correctamente a SUNAT'
                ]);
            } else {
                // Manejar diferentes tipos de error
                $errorCode = 'UNKNOWN';
                $errorMessage = 'Error desconocido';
                
                if (is_object($result['error'])) {
                    if (method_exists($result['error'], 'getCode')) {
                        $errorCode = $result['error']->getCode();
                    } elseif (property_exists($result['error'], 'code')) {
                        $errorCode = $result['error']->code;
                    }
                    
                    if (method_exists($result['error'], 'getMessage')) {
                        $errorMessage = $result['error']->getMessage();
                    } elseif (property_exists($result['error'], 'message')) {
                        $errorMessage = $result['error']->message;
                    }
                }
                
                return response()->json([
                    'success' => false,
                    'data' => $result['document'],
                    'message' => 'Error al enviar a SUNAT: ' . $errorMessage,
                    'error_code' => $errorCode
                ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el envío a SUNAT',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function downloadXml($id)
    {
        try {
            $creditNote = CreditNote::findOrFail($id);
            
            $download = $this->fileService->downloadXml($creditNote);
            
            if (!$download) {
                return response()->json([
                    'success' => false,
                    'message' => 'XML no encontrado'
                ], 404);
            }
            
            return $download;

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al descargar XML',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function downloadCdr($id)
    {
        try {
            $creditNote = CreditNote::findOrFail($id);
            
            $download = $this->fileService->downloadCdr($creditNote);
            
            if (!$download) {
                return response()->json([
                    'success' => false,
                    'message' => 'CDR no encontrado'
                ], 404);
            }
            
            return $download;

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al descargar CDR',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function downloadPdf($id)
    {
        try {
            $creditNote = CreditNote::findOrFail($id);
            
            $download = $this->fileService->downloadPdf($creditNote);
            
            if (!$download) {
                return response()->json([
                    'success' => false,
                    'message' => 'PDF no encontrado'
                ], 404);
            }
            
            return $download;

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al descargar PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function generatePdf($id): JsonResponse
    {
        try {
            $creditNote = CreditNote::with(['company', 'branch', 'client'])->findOrFail($id);
            
            $pdfResult = $this->handlePdfGeneration($creditNote, 'credit-note');
            
            return response()->json($pdfResult, $pdfResult['success'] ? 200 : 500);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getMotivos(): JsonResponse
    {
        $motivos = [
            ['code' => '01', 'name' => 'Anulación de la operación'],
            ['code' => '02', 'name' => 'Anulación por error en el RUC'],
            ['code' => '03', 'name' => 'Corrección por error en la descripción'],
            ['code' => '04', 'name' => 'Descuento global'],
            ['code' => '05', 'name' => 'Descuento por ítem'],
            ['code' => '06', 'name' => 'Devolución total'],
            ['code' => '07', 'name' => 'Devolución por ítem'],
            ['code' => '08', 'name' => 'Bonificación'],
            ['code' => '09', 'name' => 'Disminución en el valor'],
            ['code' => '10', 'name' => 'Otros conceptos'],
            ['code' => '11', 'name' => 'Ajustes de operaciones de exportación'],
            ['code' => '12', 'name' => 'Ajustes afectos al IVAP'],
            ['code' => '13', 'name' => 'Ajustes - montos y/o fechas de pago'],
        ];

        return response()->json([
            'success' => true,
            'data' => $motivos,
            'message' => 'Motivos de nota de crédito obtenidos correctamente'
        ]);
    }
}