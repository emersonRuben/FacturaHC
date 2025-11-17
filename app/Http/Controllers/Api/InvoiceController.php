<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesPdfGeneration;
use App\Services\DocumentService;
use App\Services\FileService;
use App\Models\Invoice;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\IndexInvoiceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *     name="Facturas",
 *     description="Gestión de facturas electrónicas"
 * )
 */
class InvoiceController extends Controller
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
     *     path="/api/v1/invoices",
     *     summary="Listar todas las facturas",
     *     description="Obtiene un listado paginado de facturas con filtros opcionales",
     *     tags={"Facturas"},
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
     *     @OA\Parameter(
     *         name="estado_sunat",
     *         in="query",
     *         description="Filtrar por estado SUNAT",
     *         required=false,
     *         @OA\Schema(type="string", enum={"PENDIENTE", "ACEPTADO", "RECHAZADO"}, example="ACEPTADO")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de facturas obtenida exitosamente",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="numero_completo", type="string", example="F001-000001"),
     *                     @OA\Property(property="fecha_emision", type="string", format="date", example="2025-11-17"),
     *                     @OA\Property(property="mto_imp_venta", type="number", format="float", example=236.00),
     *                     @OA\Property(property="estado_sunat", type="string", example="ACEPTADO")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="No autenticado"
     *     )
     * )
     */
    public function index(IndexInvoiceRequest $request): JsonResponse
    {
        try {
            $query = Invoice::with(['company', 'branch', 'client']);

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

            if ($request->has('fecha_desde') && $request->has('fecha_hasta')) {
                $query->whereBetween('fecha_emision', [
                    $request->fecha_desde,
                    $request->fecha_hasta
                ]);
            }

            // Paginación
            $perPage = $request->get('per_page', 15);
            $invoices = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $invoices,
                'message' => 'Facturas obtenidas correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las facturas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/invoices",
     *     summary="Crear una nueva factura",
     *     description="Crea una nueva factura electrónica",
     *     tags={"Facturas"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Datos de la factura a crear",
     *         @OA\JsonContent(
     *             required={"company_id", "branch_id", "client_id", "serie", "fecha_emision", "moneda", "items"},
     *             @OA\Property(property="company_id", type="integer", example=1, description="ID de la empresa"),
     *             @OA\Property(property="branch_id", type="integer", example=1, description="ID de la sucursal"),
     *             @OA\Property(property="client_id", type="integer", example=1, description="ID del cliente"),
     *             @OA\Property(property="serie", type="string", example="F001", description="Serie de la factura"),
     *             @OA\Property(property="fecha_emision", type="string", format="date", example="2025-11-17", description="Fecha de emisión"),
     *             @OA\Property(property="fecha_vencimiento", type="string", format="date", example="2025-12-17", description="Fecha de vencimiento"),
     *             @OA\Property(property="moneda", type="string", example="PEN", description="Código de moneda (PEN/USD)"),
     *             @OA\Property(property="tipo_operacion", type="string", example="0101", description="Tipo de operación SUNAT"),
     *             @OA\Property(
     *                 property="items",
     *                 type="array",
     *                 description="Items de la factura",
     *                 @OA\Items(
     *                     @OA\Property(property="codigo", type="string", example="PROD001"),
     *                     @OA\Property(property="descripcion", type="string", example="Producto de prueba"),
     *                     @OA\Property(property="cantidad", type="number", example=2),
     *                     @OA\Property(property="unidad_medida", type="string", example="NIU"),
     *                     @OA\Property(property="precio_unitario", type="number", example=100.00),
     *                     @OA\Property(property="tipo_afectacion_igv", type="string", example="10")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Factura creada exitosamente",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Factura creada exitosamente"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="numero_completo", type="string", example="F001-000001"),
     *                 @OA\Property(property="fecha_emision", type="string", example="2025-11-17"),
     *                 @OA\Property(property="mto_imp_venta", type="number", example=236.00)
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
     */
    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            // Crear la factura
            $invoice = $this->documentService->createInvoice($validated);

            return response()->json([
                'success' => true,
                'data' => $invoice->load(['company', 'branch', 'client']),
                'message' => 'Factura creada correctamente'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la factura',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/invoices/{id}",
     *     summary="Obtener detalle de una factura",
     *     description="Obtiene la información completa de una factura específica, incluyendo relaciones con empresa, sucursal y cliente",
     *     tags={"Facturas"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de la factura",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Factura obtenida correctamente",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Factura obtenida correctamente"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="company_id", type="integer", example=1),
     *                 @OA\Property(property="branch_id", type="integer", example=1),
     *                 @OA\Property(property="client_id", type="integer", example=1),
     *                 @OA\Property(property="tipo_comprobante", type="string", example="01"),
     *                 @OA\Property(property="serie", type="string", example="F001"),
     *                 @OA\Property(property="numero", type="string", example="000001"),
     *                 @OA\Property(property="fecha_emision", type="string", format="date", example="2025-01-17"),
     *                 @OA\Property(property="fecha_vencimiento", type="string", format="date", example="2025-01-17"),
     *                 @OA\Property(property="moneda", type="string", example="PEN"),
     *                 @OA\Property(property="total_gravadas", type="number", format="float", example=100.00),
     *                 @OA\Property(property="total_inafectas", type="number", format="float", example=0.00),
     *                 @OA\Property(property="total_exoneradas", type="number", format="float", example=0.00),
     *                 @OA\Property(property="total_gratuitas", type="number", format="float", example=0.00),
     *                 @OA\Property(property="total_descuentos", type="number", format="float", example=0.00),
     *                 @OA\Property(property="total_igv", type="number", format="float", example=18.00),
     *                 @OA\Property(property="total", type="number", format="float", example=118.00),
     *                 @OA\Property(property="estado_sunat", type="string", example="ACEPTADO"),
     *                 @OA\Property(property="observaciones_sunat", type="string", example=null),
     *                 @OA\Property(
     *                     property="company",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="razon_social", type="string", example="MI EMPRESA SAC")
     *                 ),
     *                 @OA\Property(
     *                     property="branch",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="nombre", type="string", example="Sucursal Principal")
     *                 ),
     *                 @OA\Property(
     *                     property="client",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="tipo_doc", type="string", example="6"),
     *                     @OA\Property(property="numero_doc", type="string", example="20123456789"),
     *                     @OA\Property(property="razon_social", type="string", example="CLIENTE EJEMPLO SAC")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Factura no encontrada",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Factura no encontrada"),
     *             @OA\Property(property="error", type="string", example="No query results for model [App\\Models\\Invoice]")
     *         )
     *     ),
     *     @OA\Response(response=401, description="No autenticado")
     * )
     */
    public function show($id): JsonResponse
    {
        try {
            $invoice = Invoice::with(['company', 'branch', 'client'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $invoice,
                'message' => 'Factura obtenida correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Factura no encontrada',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/invoices/{id}/send-sunat",
     *     summary="Enviar factura a SUNAT",
     *     description="Envía la factura a SUNAT para su validación y genera el XML y CDR automáticamente",
     *     tags={"Facturas"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de la factura",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Factura enviada exitosamente a SUNAT",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Factura enviada exitosamente a SUNAT"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="numero_completo", type="string", example="F001-000001"),
     *                 @OA\Property(property="estado_sunat", type="string", example="ACEPTADO"),
     *                 @OA\Property(property="xml_path", type="string", example="facturas/xml/17112025/F001-000001.xml"),
     *                 @OA\Property(property="cdr_path", type="string", example="facturas/cdr/17112025/R-F001-000001.zip")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Factura ya fue enviada a SUNAT"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Factura no encontrada"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="No autenticado"
     *     )
     * )
     */
    public function sendToSunat($id): JsonResponse
    {
        try {
            $invoice = Invoice::with(['company', 'branch', 'client'])->findOrFail($id);

            if ($invoice->estado_sunat === 'ACEPTADO') {
                return response()->json([
                    'success' => false,
                    'message' => 'La factura ya fue enviada y aceptada por SUNAT'
                ], 400);
            }

            $result = $this->documentService->sendToSunat($invoice, 'invoice');

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['document'],
                    'message' => 'Factura enviada correctamente a SUNAT'
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

    /**
     * @OA\Get(
     *     path="/api/v1/invoices/{id}/download-xml",
     *     summary="Descargar XML de la factura",
     *     description="Descarga el archivo XML de la factura generado por SUNAT",
     *     tags={"Facturas"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de la factura",
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
     *     @OA\Response(response=404, description="XML no encontrado"),
     *     @OA\Response(response=401, description="No autenticado")
     * )
     */
    public function downloadXml($id)
    {
        try {
            $invoice = Invoice::findOrFail($id);
            
            $download = $this->fileService->downloadXml($invoice);
            
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

    /**
     * @OA\Get(
     *     path="/api/v1/invoices/{id}/download-cdr",
     *     summary="Descargar CDR de la factura",
     *     description="Descarga el Comprobante de Recepción (CDR) generado por SUNAT",
     *     tags={"Facturas"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de la factura",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Archivo CDR (ZIP) descargado",
     *         @OA\MediaType(mediaType="application/zip", @OA\Schema(type="string", format="binary"))
     *     ),
     *     @OA\Response(response=404, description="CDR no encontrado"),
     *     @OA\Response(response=401, description="No autenticado")
     * )
     */
    public function downloadCdr($id)
    {
        try {
            $invoice = Invoice::findOrFail($id);
            
            $download = $this->fileService->downloadCdr($invoice);
            
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

    /**
     * @OA\Get(
     *     path="/api/v1/invoices/{id}/download-pdf",
     *     summary="Descargar PDF de la factura",
     *     description="Descarga el archivo PDF de la factura previamente generado",
     *     tags={"Facturas"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de la factura",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Archivo PDF descargado",
     *         @OA\MediaType(mediaType="application/pdf", @OA\Schema(type="string", format="binary"))
     *     ),
     *     @OA\Response(response=404, description="PDF no encontrado"),
     *     @OA\Response(response=401, description="No autenticado")
     * )
     */
    public function downloadPdf($id, Request $request)
    {
        $invoice = Invoice::findOrFail($id);
        return $this->downloadDocumentPdf($invoice, $request);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/invoices/{id}/generate-pdf",
     *     summary="Generar PDF de la factura",
     *     description="Genera el archivo PDF de la factura con el formato configurado",
     *     tags={"Facturas"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de la factura",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Parameter(
     *         name="format",
     *         in="query",
     *         description="Formato del PDF",
     *         required=false,
     *         @OA\Schema(type="string", enum={"A4", "A5", "TICKET"}, default="A4")
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
     *                 @OA\Property(property="pdf_path", type="string", example="facturas/pdf/17112025/F001-000001.pdf")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="Factura no encontrada"),
     *     @OA\Response(response=401, description="No autenticado")
     * )
     */
    public function generatePdf($id, Request $request)
    {
        $invoice = Invoice::with(['company', 'branch', 'client'])->findOrFail($id);
        return $this->generateDocumentPdf($invoice, 'invoice', $request);
    }

    protected function processInvoiceDetails(array $detalles, string $tipoOperacion = '0101'): array
    {
        // Para exportaciones (0200), no se debe calcular IGV
        $isExportacion = $tipoOperacion === '0200';

        foreach ($detalles as &$detalle) {
            $cantidad = $detalle['cantidad'];
            $valorUnitario = $detalle['mto_valor_unitario'];
            $porcentajeIgv = $isExportacion ? 0 : ($detalle['porcentaje_igv'] ?? 0);
            $tipAfeIgv = $isExportacion ? '40' : ($detalle['tip_afe_igv'] ?? '10'); // 40 = Exportación

            // Actualizar tipo de afectación para exportaciones
            $detalle['tip_afe_igv'] = $tipAfeIgv;
            $detalle['porcentaje_igv'] = $porcentajeIgv;

            // Calcular valor de venta
            $valorVenta = $cantidad * $valorUnitario;
            $detalle['mto_valor_venta'] = $valorVenta;

            // Para exportaciones - según ejemplo de Greenter
            if ($isExportacion) {
                $detalle['mto_base_igv'] = $valorVenta; // Base IGV = valor venta en exportaciones
                $detalle['igv'] = 0;
                $detalle['total_impuestos'] = 0;
                $detalle['mto_precio_unitario'] = $valorUnitario;
            } else {
                // Calcular base imponible IGV
                $baseIgv = in_array($tipAfeIgv, ['10', '17']) ? $valorVenta : 0;
                $detalle['mto_base_igv'] = $baseIgv;

                // Calcular IGV
                $igv = ($baseIgv * $porcentajeIgv) / 100;
                $detalle['igv'] = $igv;

                // Calcular impuestos totales del item
                $detalle['total_impuestos'] = $igv;

                // Calcular precio unitario (incluye impuestos)
                $detalle['mto_precio_unitario'] = ($valorVenta + $igv) / $cantidad;
            }
        }

        return $detalles;
    }
}