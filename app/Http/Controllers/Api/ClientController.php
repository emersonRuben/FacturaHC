<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

/**
 * @OA\Tag(
 *     name="Clientes",
 *     description="Gestión de clientes (personas o empresas que compran). Cada cliente puede ser asociado a múltiples comprobantes."
 * )
 */
class ClientController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/v1/clients",
     *     summary="Listar clientes",
     *     description="Obtiene un listado paginado de clientes con opciones de filtrado por empresa, tipo de documento y búsqueda por texto",
     *     tags={"Clientes"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="company_id",
     *         in="query",
     *         description="Filtrar por ID de empresa",
     *         required=false,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Parameter(
     *         name="tipo_documento",
     *         in="query",
     *         description="Tipo de documento: 1=DNI, 4=CE, 6=RUC, 7=Pasaporte, 0=Sin documento",
     *         required=false,
     *         @OA\Schema(type="string", enum={"1", "4", "6", "7", "0"})
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Búsqueda por número de documento, razón social o nombre comercial",
     *         required=false,
     *         @OA\Schema(type="string", example="12345678")
     *     ),
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Número de página",
     *         required=false,
     *         @OA\Schema(type="integer", default=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Listado de clientes obtenido correctamente",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="company_id", type="integer", example=1),
     *                     @OA\Property(property="tipo_documento", type="string", example="6"),
     *                     @OA\Property(property="numero_documento", type="string", example="20123456789"),
     *                     @OA\Property(property="razon_social", type="string", example="EMPRESA CLIENTE SAC"),
     *                     @OA\Property(property="nombre_comercial", type="string", example="Cliente SAC"),
     *                     @OA\Property(property="direccion", type="string", example="Av. Principal 123"),
     *                     @OA\Property(property="telefono", type="string", example="987654321"),
     *                     @OA\Property(property="email", type="string", example="cliente@empresa.com")
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="meta",
     *                 type="object",
     *                 @OA\Property(property="total", type="integer", example=50),
     *                 @OA\Property(property="per_page", type="integer", example=20),
     *                 @OA\Property(property="current_page", type="integer", example=1),
     *                 @OA\Property(property="last_page", type="integer", example=3)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="No autenticado"),
     *     @OA\Response(response=500, description="Error del servidor")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Client::with(['company:id,ruc,razon_social']);

            // Filtrar por empresa si se proporciona
            if ($request->has('company_id')) {
                $query->where('company_id', $request->company_id);
            }

            // Filtrar por tipo de documento
            if ($request->has('tipo_documento')) {
                $query->where('tipo_documento', $request->tipo_documento);
            }

            // Búsqueda por texto
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('numero_documento', 'like', "%{$search}%")
                      ->orWhere('razon_social', 'like', "%{$search}%")
                      ->orWhere('nombre_comercial', 'like', "%{$search}%");
                });
            }

            $clients = $query->paginate(20);

            return response()->json([
                'success' => true,
                'data' => $clients->items(),
                'meta' => [
                    'total' => $clients->total(),
                    'per_page' => $clients->perPage(),
                    'current_page' => $clients->currentPage(),
                    'last_page' => $clients->lastPage()
                ]
            ]);

        } catch (Exception $e) {
            Log::error("Error al listar clientes", [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener clientes: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/clients",
     *     summary="Crear nuevo cliente",
     *     description="Crea un nuevo cliente (persona o empresa) que podrá recibir comprobantes electrónicos",
     *     tags={"Clientes"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"tipo_documento", "numero_documento", "razon_social"},
     *             @OA\Property(property="company_id", type="integer", example=1, description="ID de la empresa (opcional, se tomará del usuario autenticado)"),
     *             @OA\Property(property="tipo_documento", type="string", enum={"1", "4", "6", "7", "0"}, example="6", description="1=DNI, 4=CE, 6=RUC, 7=Pasaporte, 0=Sin documento"),
     *             @OA\Property(property="numero_documento", type="string", maxLength=20, example="20123456789", description="Número de documento"),
     *             @OA\Property(property="razon_social", type="string", maxLength=255, example="EMPRESA CLIENTE SAC", description="Razón social o nombre completo"),
     *             @OA\Property(property="nombre_comercial", type="string", maxLength=255, example="Cliente SAC", description="Nombre comercial (opcional)"),
     *             @OA\Property(property="direccion", type="string", maxLength=255, example="Av. Principal 123", description="Dirección fiscal"),
     *             @OA\Property(property="ubigeo", type="string", minLength=6, maxLength=6, example="150101", description="Código de ubigeo (6 dígitos)"),
     *             @OA\Property(property="distrito", type="string", maxLength=100, example="Lima", description="Distrito"),
     *             @OA\Property(property="provincia", type="string", maxLength=100, example="Lima", description="Provincia"),
     *             @OA\Property(property="departamento", type="string", maxLength=100, example="Lima", description="Departamento"),
     *             @OA\Property(property="telefono", type="string", maxLength=20, example="987654321", description="Teléfono de contacto"),
     *             @OA\Property(property="email", type="string", format="email", maxLength=255, example="cliente@empresa.com", description="Correo electrónico"),
     *             @OA\Property(property="activo", type="boolean", example=true, description="Estado del cliente (true=activo, false=inactivo)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Cliente creado exitosamente",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Cliente creado exitosamente"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="company_id", type="integer", example=1),
     *                 @OA\Property(property="tipo_documento", type="string", example="6"),
     *                 @OA\Property(property="numero_documento", type="string", example="20123456789"),
     *                 @OA\Property(property="razon_social", type="string", example="EMPRESA CLIENTE SAC"),
     *                 @OA\Property(property="email", type="string", example="cliente@empresa.com"),
     *                 @OA\Property(
     *                     property="company",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="ruc", type="string", example="20123456789"),
     *                     @OA\Property(property="razon_social", type="string", example="MI EMPRESA SAC")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Cliente duplicado",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Ya existe un cliente con el mismo tipo y número de documento en esta empresa")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Errores de validación",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Errores de validación"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     ),
     *     @OA\Response(response=401, description="No autenticado"),
     *     @OA\Response(response=500, description="Error del servidor")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'nullable|integer|exists:companies,id',
                'tipo_documento' => 'required|string|in:1,4,6,7,0', // DNI, CE, RUC, PAS, SIN DOC
                'numero_documento' => 'required|string|max:20',
                'razon_social' => 'required|string|max:255',
                'nombre_comercial' => 'nullable|string|max:255',
                'direccion' => 'nullable|string|max:255',
                'ubigeo' => 'nullable|string|size:6',
                'distrito' => 'nullable|string|max:100',
                'provincia' => 'nullable|string|max:100',
                'departamento' => 'nullable|string|max:100',
                'telefono' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:255',
                'activo' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Errores de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verificar que no exista otro cliente con el mismo documento en la misma empresa
            $existingClient = Client::where('company_id', $request->company_id)
                                   ->where('tipo_documento', $request->tipo_documento)
                                   ->where('numero_documento', $request->numero_documento)
                                   ->first();

            if ($existingClient) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya existe un cliente con el mismo tipo y número de documento en esta empresa'
                ], 400);
            }

            // Verificar que la empresa existe y está activa
            $company = Company::where('id', $request->company_id)
                             ->where('activo', true)
                             ->first();

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'La empresa especificada no existe o está inactiva'
                ], 404);
            }

            $client = Client::create($validator->validated());

            Log::info("Cliente creado exitosamente", [
                'client_id' => $client->id,
                'company_id' => $client->company_id,
                'numero_documento' => $client->numero_documento,
                'razon_social' => $client->razon_social
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Cliente creado exitosamente',
                'data' => $client->load('company:id,ruc,razon_social')
            ], 201);

        } catch (Exception $e) {
            Log::error("Error al crear cliente", [
                'request_data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al crear cliente: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/clients/{id}",
     *     summary="Obtener detalle de un cliente",
     *     description="Obtiene la información completa de un cliente específico",
     *     tags={"Clientes"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID del cliente",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Cliente obtenido correctamente",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="company_id", type="integer", example=1),
     *                 @OA\Property(property="tipo_documento", type="string", example="6"),
     *                 @OA\Property(property="numero_documento", type="string", example="20123456789"),
     *                 @OA\Property(property="razon_social", type="string", example="EMPRESA CLIENTE SAC"),
     *                 @OA\Property(property="direccion", type="string", example="Av. Principal 123"),
     *                 @OA\Property(property="email", type="string", example="cliente@empresa.com")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="Cliente no encontrado"),
     *     @OA\Response(response=401, description="No autenticado")
     * )
     */
    public function show(Client $client): JsonResponse
    {
        try {
            $client->load(['company:id,ruc,razon_social,nombre_comercial']);

            return response()->json([
                'success' => true,
                'data' => $client
            ]);

        } catch (Exception $e) {
            Log::error("Error al obtener cliente", [
                'client_id' => $client->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener cliente: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/v1/clients/{id}",
     *     summary="Actualizar cliente",
     *     description="Actualiza la información de un cliente existente",
     *     tags={"Clientes"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID del cliente",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"company_id", "tipo_documento", "numero_documento", "razon_social"},
     *             @OA\Property(property="company_id", type="integer", example=1),
     *             @OA\Property(property="tipo_documento", type="string", enum={"1", "4", "6", "7", "0"}, example="6"),
     *             @OA\Property(property="numero_documento", type="string", example="20123456789"),
     *             @OA\Property(property="razon_social", type="string", example="EMPRESA CLIENTE SAC ACTUALIZADA"),
     *             @OA\Property(property="nombre_comercial", type="string", example="Cliente SAC"),
     *             @OA\Property(property="direccion", type="string", example="Av. Principal 456"),
     *             @OA\Property(property="email", type="string", example="cliente@empresa.com"),
     *             @OA\Property(property="telefono", type="string", example="987654321"),
     *             @OA\Property(property="activo", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Cliente actualizado correctamente",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Cliente actualizado exitosamente"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Cliente no encontrado"),
     *     @OA\Response(response=422, description="Errores de validación"),
     *     @OA\Response(response=401, description="No autenticado")
     * )
     */
    public function update(Request $request, Client $client): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer|exists:companies,id',
                'tipo_documento' => 'required|string|in:1,4,6,7,0',
                'numero_documento' => 'required|string|max:20',
                'razon_social' => 'required|string|max:255',
                'nombre_comercial' => 'nullable|string|max:255',
                'direccion' => 'nullable|string|max:255',
                'ubigeo' => 'nullable|string|size:6',
                'distrito' => 'nullable|string|max:100',
                'provincia' => 'nullable|string|max:100',
                'departamento' => 'nullable|string|max:100',
                'telefono' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:255',
                'activo' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Errores de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verificar que no exista otro cliente con el mismo documento en la misma empresa
            $existingClient = Client::where('company_id', $request->company_id)
                                   ->where('tipo_documento', $request->tipo_documento)
                                   ->where('numero_documento', $request->numero_documento)
                                   ->where('id', '!=', $client->id)
                                   ->first();

            if ($existingClient) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya existe otro cliente con el mismo tipo y número de documento en esta empresa'
                ], 400);
            }

            $client->update($validator->validated());

            Log::info("Cliente actualizado exitosamente", [
                'client_id' => $client->id,
                'company_id' => $client->company_id,
                'changes' => $client->getChanges()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Cliente actualizado exitosamente',
                'data' => $client->fresh()->load('company:id,ruc,razon_social')
            ]);

        } catch (Exception $e) {
            Log::error("Error al actualizar cliente", [
                'client_id' => $client->id,
                'request_data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar cliente: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/clients/{id}",
     *     summary="Eliminar cliente",
     *     description="Marca un cliente como inactivo (eliminación lógica). No elimina físicamente el registro.",
     *     tags={"Clientes"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID del cliente",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Cliente marcado como inactivo correctamente",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Cliente marcado como inactivo correctamente")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Cliente no encontrado"),
     *     @OA\Response(response=401, description="No autenticado")
     * )
     */
    public function destroy(Client $client): JsonResponse
    {
        try {
            // Verificar si el cliente tiene documentos asociados
            $hasDocuments = false; // Podrías implementar estas verificaciones si es necesario
            // $hasDocuments = $client->invoices()->count() > 0 ||
            //                $client->boletas()->count() > 0 ||
            //                $client->dispatchGuides()->count() > 0;

            if ($hasDocuments) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar el cliente porque tiene documentos asociados. Considere desactivarlo en su lugar.'
                ], 400);
            }

            // Marcar como inactivo en lugar de eliminar
            $client->update(['activo' => false]);

            Log::warning("Cliente desactivado", [
                'client_id' => $client->id,
                'numero_documento' => $client->numero_documento,
                'razon_social' => $client->razon_social
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Cliente desactivado exitosamente'
            ]);

        } catch (Exception $e) {
            Log::error("Error al desactivar cliente", [
                'client_id' => $client->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al desactivar cliente: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Activar cliente
     */
    public function activate(Client $client): JsonResponse
    {
        try {
            $client->update(['activo' => true]);

            Log::info("Cliente activado", [
                'client_id' => $client->id,
                'numero_documento' => $client->numero_documento,
                'razon_social' => $client->razon_social
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Cliente activado exitosamente',
                'data' => $client->load('company:id,ruc,razon_social')
            ]);

        } catch (Exception $e) {
            Log::error("Error al activar cliente", [
                'client_id' => $client->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al activar cliente: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener clientes de una empresa específica
     */
    public function getByCompany(Company $company): JsonResponse
    {
        try {
            $clients = $company->clients()
                             ->select([
                                 'id', 'company_id', 'tipo_documento', 'numero_documento',
                                 'razon_social', 'nombre_comercial', 'direccion',
                                 'distrito', 'provincia', 'departamento',
                                 'telefono', 'email', 'activo',
                                 'created_at', 'updated_at'
                             ])
                             ->orderBy('razon_social')
                             ->paginate(50);

            return response()->json([
                'success' => true,
                'data' => $clients->items(),
                'meta' => [
                    'company_id' => $company->id,
                    'company_name' => $company->razon_social,
                    'total' => $clients->total(),
                    'per_page' => $clients->perPage(),
                    'current_page' => $clients->currentPage(),
                    'last_page' => $clients->lastPage()
                ]
            ]);

        } catch (Exception $e) {
            Log::error("Error al obtener clientes por empresa", [
                'company_id' => $company->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener clientes: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Buscar cliente por número de documento
     */
    public function searchByDocument(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer|exists:companies,id',
                'tipo_documento' => 'required|string|in:1,4,6,7,0',
                'numero_documento' => 'required|string|max:20'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Errores de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $client = Client::where('company_id', $request->company_id)
                           ->where('tipo_documento', $request->tipo_documento)
                           ->where('numero_documento', $request->numero_documento)
                           ->where('activo', true)
                           ->with('company:id,ruc,razon_social')
                           ->first();

            if (!$client) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cliente no encontrado'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $client
            ]);

        } catch (Exception $e) {
            Log::error("Error al buscar cliente por documento", [
                'request_data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al buscar cliente: ' . $e->getMessage()
            ], 500);
        }
    }
}