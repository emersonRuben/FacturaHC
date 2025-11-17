<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DocumentService;
use App\Services\FileService;
use App\Models\DebitNote;
use App\Http\Requests\IndexDebitNoteRequest;
use App\Http\Requests\StoreDebitNoteRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *     name="Notas de Débito",
 *     description="Gestión de notas de débito electrónicas. Se emiten para aumentar el importe de una factura o boleta."
 * )
 */
class DebitNoteController extends Controller
{
    protected $documentService;
    protected $fileService;

    public function __construct(DocumentService $documentService, FileService $fileService)
    {
        $this->documentService = $documentService;
        $this->fileService = $fileService;
    }

    /**
     * @OA\Get(
     *     path="/api/v1/debit-notes",
     *     summary="Listar notas de débito",
     *     description="Obtiene un listado paginado de notas de débito",
     *     tags={"Notas de Débito"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="company_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="estado_sunat", in="query", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Listado de notas de débito"),
     *     @OA\Response(response=401, description="No autenticado")
     * )
     */
    public function index(IndexDebitNoteRequest $request): JsonResponse
    {
        try {
            $query = DebitNote::with(['company', 'branch', 'client']);

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
            $debitNotes = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $debitNotes,
                'message' => 'Notas de débito obtenidas correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las notas de débito',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(StoreDebitNoteRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            // Crear la nota de débito
            $debitNote = $this->documentService->createDebitNote($validated);

            return response()->json([
                'success' => true,
                'data' => $debitNote->load(['company', 'branch', 'client']),
                'message' => 'Nota de débito creada correctamente'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la nota de débito',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $debitNote = DebitNote::with(['company', 'branch', 'client'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $debitNote,
                'message' => 'Nota de débito obtenida correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Nota de débito no encontrada',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function sendToSunat($id): JsonResponse
    {
        try {
            $debitNote = DebitNote::with(['company', 'branch', 'client'])->findOrFail($id);

            if ($debitNote->estado_sunat === 'ACEPTADO') {
                return response()->json([
                    'success' => false,
                    'message' => 'La nota de débito ya fue enviada y aceptada por SUNAT'
                ], 400);
            }

            $result = $this->documentService->sendToSunat($debitNote, 'debit_note');

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['document'],
                    'message' => 'Nota de débito enviada correctamente a SUNAT'
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
            $debitNote = DebitNote::findOrFail($id);
            
            $download = $this->fileService->downloadXml($debitNote);
            
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
            $debitNote = DebitNote::findOrFail($id);
            
            $download = $this->fileService->downloadCdr($debitNote);
            
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
            $debitNote = DebitNote::findOrFail($id);
            
            $download = $this->fileService->downloadPdf($debitNote);
            
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

    public function getMotivos(): JsonResponse
    {
        $motivos = [
            ['code' => '01', 'name' => 'Intereses por mora'],
            ['code' => '02', 'name' => 'Aumento en el valor'],
            ['code' => '03', 'name' => 'Penalidades/otros conceptos'],
            ['code' => '10', 'name' => 'Ajustes de operaciones de exportación'],
            ['code' => '11', 'name' => 'Ajustes afectos al IVAP'],
        ];

        return response()->json([
            'success' => true,
            'data' => $motivos,
            'message' => 'Motivos de nota de débito obtenidos correctamente'
        ]);
    }

    public function generatePdf($id): JsonResponse
    {
        try {
            $debitNote = DebitNote::with(['company', 'branch', 'client'])->findOrFail($id);
            
            // Generar PDF usando DocumentService
            $this->documentService->generateDebitNotePdf($debitNote);
            
            // Recargar el modelo para obtener el pdf_path actualizado
            $debitNote->refresh();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $debitNote->id,
                    'serie' => $debitNote->serie,
                    'correlativo' => $debitNote->correlativo,
                    'numero_documento' => $debitNote->serie . '-' . $debitNote->correlativo,
                    'fecha_emision' => $debitNote->fecha_emision,
                    'pdf_path' => $debitNote->pdf_path,
                    'pdf_url' => $debitNote->pdf_path ? url('storage/' . $debitNote->pdf_path) : null,
                    'download_url' => url("/api/v1/debit-notes/{$debitNote->id}/download-pdf"),
                    'estado_sunat' => $debitNote->estado_sunat,
                    'mto_imp_venta' => $debitNote->mto_imp_venta,
                    'moneda' => $debitNote->moneda,
                    'client' => [
                        'numero_documento' => $debitNote->client->numero_documento ?? null,
                        'razon_social' => $debitNote->client->razon_social ?? null,
                    ]
                ],
                'message' => 'PDF de nota de débito generado correctamente'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}