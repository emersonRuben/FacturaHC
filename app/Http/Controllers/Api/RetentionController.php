<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesPdfGeneration;
use App\Services\DocumentService;
use App\Services\FileService;
use App\Models\Retention;
use App\Http\Requests\StoreRetentionRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class RetentionController extends Controller
{
    use HandlesPdfGeneration;
    
    protected $documentService;
    protected $fileService;

    public function __construct(DocumentService $documentService, FileService $fileService)
    {
        $this->documentService = $documentService;
        $this->fileService = $fileService;
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $query = Retention::with(['company', 'branch', 'proveedor']);

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

            $retentions = $query->orderBy('fecha_emision', 'desc')
                              ->paginate(10);

            return response()->json([
                'success' => true,
                'data' => $retentions
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las retenciones: ' . $e->getMessage()
            ], 500);
        }
    }

    public function store(StoreRetentionRequest $request): JsonResponse
    {
        try {
            $retention = $this->documentService->createRetention($request->validated());
            
            return response()->json([
                'success' => true,
                'message' => 'Comprobante de retención creado exitosamente',
                'data' => $retention->load(['company', 'branch', 'proveedor'])
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el comprobante de retención: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $retention = Retention::with(['company', 'branch', 'proveedor'])->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $retention
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Comprobante de retención no encontrado'
            ], 404);
        }
    }

    public function sendToSunat($id): JsonResponse
    {
        try {
            $retention = Retention::findOrFail($id);
            $result = $this->documentService->sendRetentionToSunat($retention);
            
            return response()->json([
                'success' => true,
                'message' => 'Comprobante de retención enviado a SUNAT',
                'data' => $result
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al enviar a SUNAT: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function downloadXml($id)
    {
        try {
            $retention = Retention::findOrFail($id);
            return $this->fileService->downloadXml($retention, 'retention');

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al descargar XML: ' . $e->getMessage()
            ], 500);
        }
    }

    public function downloadCdr($id)
    {
        try {
            $retention = Retention::findOrFail($id);
            return $this->fileService->downloadCdr($retention, 'retention');

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al descargar CDR: ' . $e->getMessage()
            ], 500);
        }
    }

    public function downloadPdf($id)
    {
        try {
            $retention = Retention::findOrFail($id);
            return $this->fileService->downloadPdf($retention, 'retention');

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al descargar PDF: ' . $e->getMessage()
            ], 500);
        }
    }

    public function generatePdf(Request $request, $id): JsonResponse
    {
        return $this->handlePdfGeneration($request, $id, 'retention');
    }
}