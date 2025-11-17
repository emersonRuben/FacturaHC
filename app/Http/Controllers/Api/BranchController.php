<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\StoreBranchRequest;
use App\Http\Requests\Branch\UpdateBranchRequest;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * @OA\Tag(
 *     name="Sucursales",
 *     description="Gestión de sucursales o establecimientos de la empresa. Cada sucursal puede emitir sus propios comprobantes."
 * )
 */
class BranchController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/v1/branches",
     *     summary="Listar sucursales",
     *     description="Obtiene el listado de sucursales, con opción de filtrar por empresa",
     *     tags={"Sucursales"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="company_id",
     *         in="query",
     *         description="Filtrar por ID de empresa",
     *         required=false,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Listado de sucursales",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="company_id", type="integer", example=1),
     *                     @OA\Property(property="codigo", type="string", example="0000"),
     *                     @OA\Property(property="nombre", type="string", example="Sucursal Principal"),
     *                     @OA\Property(property="direccion", type="string", example="Av. Principal 123"),
     *                     @OA\Property(property="ubigeo", type="string", example="150101")
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="meta",
     *                 type="object",
     *                 @OA\Property(property="total", type="integer", example=5)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="No autenticado")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Branch::with(['company:id,ruc,razon_social']);

            // Filtrar por empresa si se proporciona
            if ($request->has('company_id')) {
                $query->where('company_id', $request->company_id);
            }

            $branches = $query->get();

            return response()->json([
                'success' => true,
                'data' => $branches,
                'meta' => [
                    'total' => $branches->count(),
                    'companies_count' => $branches->unique('company_id')->count()
                ]
            ]);

        } catch (Exception $e) {
            Log::error("Error al listar sucursales", [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener sucursales: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/branches",
     *     summary="Crear sucursal",
     *     description="Crea una nueva sucursal o establecimiento anexo",
     *     tags={"Sucursales"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"company_id", "codigo", "nombre", "direccion", "ubigeo"},
     *             @OA\Property(property="company_id", type="integer", example=1, description="ID de la empresa"),
     *             @OA\Property(property="codigo", type="string", example="0000", description="Código de establecimiento SUNAT (0000=principal)"),
     *             @OA\Property(property="nombre", type="string", example="Sucursal Principal", description="Nombre de la sucursal"),
     *             @OA\Property(property="direccion", type="string", example="Av. Principal 123", description="Dirección fiscal"),
     *             @OA\Property(property="ubigeo", type="string", example="150101", description="Código de ubigeo (6 dígitos)"),
     *             @OA\Property(property="distrito", type="string", example="Lima", description="Distrito"),
     *             @OA\Property(property="provincia", type="string", example="Lima", description="Provincia"),
     *             @OA\Property(property="departamento", type="string", example="Lima", description="Departamento"),
     *             @OA\Property(property="telefono", type="string", example="014567890", description="Teléfono"),
     *             @OA\Property(property="email", type="string", example="sucursal@empresa.com", description="Correo electrónico")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Sucursal creada exitosamente",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Sucursal creada exitosamente"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Empresa no encontrada"),
     *     @OA\Response(response=422, description="Errores de validación"),
     *     @OA\Response(response=401, description="No autenticado")
     * )
     */
    public function store(StoreBranchRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            
            // Verificar que la empresa existe y está activa
            $company = Company::where('id', $validated['company_id'])
                             ->where('activo', true)
                             ->first();

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'La empresa especificada no existe o está inactiva'
                ], 404);
            }

            $branch = Branch::create($validated);

            Log::info("Sucursal creada exitosamente", [
                'branch_id' => $branch->id,
                'company_id' => $branch->company_id,
                'nombre' => $branch->nombre
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Sucursal creada exitosamente',
                'data' => $branch->load('company:id,ruc,razon_social')
            ], 201);

        } catch (Exception $e) {
            Log::error("Error al crear sucursal", [
                'request_data' => $validated ?? [],
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al crear sucursal: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener sucursal específica
     */
    public function show(Branch $branch): JsonResponse
    {
        try {
            $branch->load(['company:id,ruc,razon_social,nombre_comercial']);

            return response()->json([
                'success' => true,
                'data' => $branch
            ]);

        } catch (Exception $e) {
            Log::error("Error al obtener sucursal", [
                'branch_id' => $branch->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener sucursal: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar sucursal
     */
    public function update(UpdateBranchRequest $request, Branch $branch): JsonResponse
    {
        try {
            $validated = $request->validated();
            
            // Verificar que la empresa nueva existe y está activa (si se está cambiando)
            if (isset($validated['company_id'])) {
                $company = Company::where('id', $validated['company_id'])
                                 ->where('activo', true)
                                 ->first();

                if (!$company) {
                    return response()->json([
                        'success' => false,
                        'message' => 'La empresa especificada no existe o está inactiva'
                    ], 404);
                }
            }

            $branch->update($validated);

            Log::info("Sucursal actualizada exitosamente", [
                'branch_id' => $branch->id,
                'company_id' => $branch->company_id,
                'changes' => $branch->getChanges()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Sucursal actualizada exitosamente',
                'data' => $branch->fresh()->load('company:id,ruc,razon_social')
            ]);

        } catch (Exception $e) {
            Log::error("Error al actualizar sucursal", [
                'branch_id' => $branch->id,
                'request_data' => $validated ?? [],
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar sucursal: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar sucursal (soft delete - marcar como inactiva)
     */
    public function destroy(Branch $branch): JsonResponse
    {
        try {
            // Verificar si la sucursal tiene documentos asociados
            $hasDocuments = false; // Podrías implementar estas verificaciones si es necesario
            // $hasDocuments = $branch->invoices()->count() > 0 ||
            //                $branch->dispatchGuides()->count() > 0;

            if ($hasDocuments) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar la sucursal porque tiene documentos asociados. Considere desactivarla en su lugar.'
                ], 400);
            }

            // Marcar como inactiva en lugar de eliminar
            $branch->update(['activo' => false]);

            Log::warning("Sucursal desactivada", [
                'branch_id' => $branch->id,
                'nombre' => $branch->nombre
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Sucursal desactivada exitosamente'
            ]);

        } catch (Exception $e) {
            Log::error("Error al desactivar sucursal", [
                'branch_id' => $branch->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al desactivar sucursal: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Activar sucursal
     */
    public function activate(Branch $branch): JsonResponse
    {
        try {
            $branch->update(['activo' => true]);

            Log::info("Sucursal activada", [
                'branch_id' => $branch->id,
                'nombre' => $branch->nombre
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Sucursal activada exitosamente',
                'data' => $branch->load('company:id,ruc,razon_social')
            ]);

        } catch (Exception $e) {
            Log::error("Error al activar sucursal", [
                'branch_id' => $branch->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al activar sucursal: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener sucursales de una empresa específica
     */
    public function getByCompany(Company $company): JsonResponse
    {
        try {
            $branches = $company->branches()
                              ->select([
                                  'id', 'company_id', 'nombre', 'direccion',
                                  'distrito', 'provincia', 'departamento',
                                  'telefono', 'email', 'activo',
                                  'created_at', 'updated_at'
                              ])
                              ->get();

            return response()->json([
                'success' => true,
                'data' => $branches,
                'meta' => [
                    'company_id' => $company->id,
                    'company_name' => $company->razon_social,
                    'total_branches' => $branches->count(),
                    'active_branches' => $branches->where('activo', true)->count()
                ]
            ]);

        } catch (Exception $e) {
            Log::error("Error al obtener sucursales por empresa", [
                'company_id' => $company->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener sucursales: ' . $e->getMessage()
            ], 500);
        }
    }
}