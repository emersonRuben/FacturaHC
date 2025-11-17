<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserBelongsToCompany
{
    /**
     * Valida que el usuario solo pueda acceder a datos de su propia empresa
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Si el usuario no está autenticado, dejamos que auth:sanctum maneje el error
        if (!$user) {
            return $next($request);
        }

        // Si es super_admin, puede acceder a todo
        if ($user->role && $user->role->name === 'super_admin') {
            return $next($request);
        }

        // Si el usuario no tiene empresa asignada, rechazar
        if (!$user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario sin empresa asignada. Contacte al administrador.'
            ], 403);
        }

        // Validar company_id en query params
        if ($request->has('company_id')) {
            $requestedCompanyId = $request->input('company_id');
            
            if ($requestedCompanyId != $user->company_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tiene permisos para acceder a los datos de otra empresa'
                ], 403);
            }
        }

        // Validar company_id en request body (POST, PUT, PATCH)
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH'])) {
            $bodyData = $request->all();
            
            if (isset($bodyData['company_id']) && $bodyData['company_id'] != $user->company_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No puede crear o modificar datos de otra empresa'
                ], 403);
            }

            // Si no se proporciona company_id, forzar el del usuario autenticado
            if (!isset($bodyData['company_id'])) {
                $request->merge(['company_id' => $user->company_id]);
            }
        }

        // Si no se especifica company_id en GET, forzar el del usuario
        if ($request->method() === 'GET' && !$request->has('company_id')) {
            $request->merge(['company_id' => $user->company_id]);
        }

        return $next($request);
    }
}
