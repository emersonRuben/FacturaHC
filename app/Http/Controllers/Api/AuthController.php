<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rules\Password;

/**
 * @OA\Info(
 *     title="API SUNAT - Sistema de Facturación Electrónica",
 *     version="1.0.0",
 *     description="API REST para emisión de comprobantes electrónicos SUNAT (Facturas, Boletas, Notas de Crédito/Débito)",
 *     @OA\Contact(
 *         email="soporte@tuempresa.com",
 *         name="Soporte Técnico"
 *     ),
 *     @OA\License(
 *         name="Privada",
 *         url="https://tuempresa.com/license"
 *     )
 * )
 * 
 * @OA\Server(
 *     url=L5_SWAGGER_CONST_HOST,
 *     description="Servidor de Producción"
 * )
 * 
 * @OA\Server(
 *     url="http://localhost:8000",
 *     description="Servidor Local (Desarrollo)"
 * )
 * 
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="Sanctum",
 *     description="Ingrese su token de acceso obtenido del endpoint /api/auth/login"
 * )
 * 
 * @OA\Tag(
 *     name="Autenticación",
 *     description="Endpoints de autenticación y gestión de usuarios"
 * )
 * 
 * @OA\Tag(
 *     name="Boletas",
 *     description="Gestión de boletas de venta electrónicas"
 * )
 */
class AuthController extends Controller
{
    /**
     * Inicializar sistema - Crear primer super admin
     */
    public function initialize(Request $request)
    {
        // Verificar si ya hay usuarios en el sistema
        if (User::count() > 0) {
            return response()->json([
                'message' => 'Sistema ya inicializado',
                'status' => 'error'
            ], 400);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', Password::min(8)->letters()->mixedCase()->numbers()],
        ]);

        try {
            // Ejecutar seeder completo de roles y permisos automáticamente
            $this->runRolesAndPermissionsSeeder();
            // Obtener rol de super admin
            $superAdminRole = Role::where('name', 'super_admin')->first();
            // Crear primer usuario super admin
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role_id' => $superAdminRole->id,
                'user_type' => 'system',
                'active' => true,
                'email_verified_at' => now(),
            ]);

            // Crear token de acceso
            $token = $user->createToken('API_INIT_TOKEN', ['*'])->plainTextToken;

            return response()->json([
                'message' => 'Sistema inicializado exitosamente',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->display_name
                ],
                'access_token' => $token,
                'token_type' => 'Bearer'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al inicializar sistema: ' . $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/auth/login",
     *     summary="Iniciar sesión",
     *     description="Autenticación de usuario y obtención de token de acceso",
     *     tags={"Autenticación"},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Credenciales de acceso",
     *         @OA\JsonContent(
     *             required={"email", "password"},
     *             @OA\Property(property="email", type="string", format="email", example="admin@example.com", description="Correo electrónico del usuario"),
     *             @OA\Property(property="password", type="string", format="password", example="Password123", description="Contraseña del usuario")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Login exitoso",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Login exitoso"),
     *             @OA\Property(
     *                 property="user",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Admin Principal"),
     *                 @OA\Property(property="email", type="string", example="admin@example.com"),
     *                 @OA\Property(property="role", type="string", example="Super Administrador"),
     *                 @OA\Property(property="company_id", type="integer", example=1)
     *             ),
     *             @OA\Property(property="access_token", type="string", example="1|cMSlQ0p5NJbcQl1V3KRPuU0wZOFOpGVxWXJXKJ1Wb7a86a41"),
     *             @OA\Property(property="token_type", type="string", example="Bearer")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Credenciales incorrectas",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Credenciales incorrectas"),
     *             @OA\Property(property="status", type="string", example="error")
     *         )
     *     )
     * )
     * 
     * Login - Autenticación
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Credenciales incorrectas',
                'status' => 'error'
            ], 401);
        }

        if (!$user->active) {
            return response()->json([
                'message' => 'Usuario inactivo',
                'status' => 'error'
            ], 401);
        }

        if ($user->isLocked()) {
            return response()->json([
                'message' => 'Usuario bloqueado',
                'status' => 'error'
            ], 401);
        }

        $user->recordSuccessfulLogin($request->ip());

        $abilities = $user->role ? $user->role->getAllPermissions() : ['*'];
        
        // Determinar duración del token según el tipo de usuario
        $expiresAt = match($user->user_type) {
            'system' => now()->addDays(7),           // System/Super admin: 7 días
            'api_client' => now()->addHours(24),     // Clientes API: 24 horas
            'user' => now()->addHours(12),           // Usuarios internos: 12 horas
            default => now()->addMinutes(env('SANCTUM_EXPIRATION', 1440))
        };
        
        $token = $user->createToken('API_ACCESS_TOKEN', $abilities, $expiresAt)->plainTextToken;

        return response()->json([
            'message' => 'Login exitoso',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role ? $user->role->display_name : 'Sin rol',
                'company_id' => $user->company_id,
                'permissions' => $abilities
            ],
            'access_token' => $token,
            'token_type' => 'Bearer'
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/logout",
     *     summary="Cerrar sesión",
     *     description="Invalida el token de acceso actual del usuario",
     *     tags={"Autenticación"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Sesión cerrada exitosamente",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Logout exitoso")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="No autenticado"
     *     )
     * )
     * 
     * Logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout exitoso'
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/auth/me",
     *     summary="Obtener información del usuario autenticado",
     *     description="Retorna los datos del usuario actualmente autenticado",
     *     tags={"Autenticación"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Información del usuario obtenida exitosamente",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="user",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Admin Principal"),
     *                 @OA\Property(property="email", type="string", example="admin@example.com"),
     *                 @OA\Property(property="user_type", type="string", example="system"),
     *                 @OA\Property(property="active", type="boolean", example=true),
     *                 @OA\Property(
     *                     property="role",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="super_admin"),
     *                     @OA\Property(property="display_name", type="string", example="Super Administrador")
     *                 ),
     *                 @OA\Property(
     *                     property="company",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="razon_social", type="string", example="MI EMPRESA SAC")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="No autenticado"
     *     )
     * )
     * 
     * Información del usuario autenticado
     */
    public function me(Request $request)
    {
        $user = $request->user()->load('role', 'company');

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role ? $user->role->display_name : 'Sin rol',
                'company' => $user->company ? $user->company->razon_social : null,
                'permissions' => $user->getAllPermissions(),
                'last_login_at' => $user->last_login_at,
                'created_at' => $user->created_at
            ]
        ]);
    }

    /**
     * Crear usuarios adicionales (solo super admin)
     */
    public function createUser(Request $request)
    {
        if (!$request->user()->hasRole('super_admin')) {
            return response()->json([
                'message' => 'No tienes permisos para crear usuarios',
                'status' => 'error'
            ], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', Password::min(8)],
            'role_name' => 'required|string|exists:roles,name',
            'company_id' => 'nullable|integer|exists:companies,id',
            'user_type' => 'required|in:system,user,api_client',
        ]);

        try {
            $role = Role::where('name', $request->role_name)->first();

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role_id' => $role->id,
                'company_id' => $request->company_id,
                'user_type' => $request->user_type,
                'active' => true,
                'email_verified_at' => now(),
            ]);

            return response()->json([
                'message' => 'Usuario creado exitosamente',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->display_name,
                    'user_type' => $user->user_type
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al crear usuario: ' . $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    /**
     * Desactivar usuario (solo super admin)
     */
    public function deactivateUser(Request $request, $userId)
    {
        if (!$request->user()->hasRole('super_admin')) {
            return response()->json([
                'message' => 'No tienes permisos para desactivar usuarios',
                'status' => 'error'
            ], 403);
        }

        try {
            $user = User::findOrFail($userId);

            // No permitir desactivar super admins
            if ($user->role && $user->role->name === 'super_admin') {
                return response()->json([
                    'message' => 'No se puede desactivar un super administrador',
                    'status' => 'error'
                ], 403);
            }

            $user->active = false;
            $user->save();

            // Revocar todos los tokens activos
            $user->tokens()->delete();

            return response()->json([
                'message' => 'Usuario desactivado exitosamente',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'active' => $user->active
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al desactivar usuario: ' . $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    /**
     * Activar usuario (solo super admin)
     */
    public function activateUser(Request $request, $userId)
    {
        if (!$request->user()->hasRole('super_admin')) {
            return response()->json([
                'message' => 'No tienes permisos para activar usuarios',
                'status' => 'error'
            ], 403);
        }

        try {
            $user = User::findOrFail($userId);
            $user->active = true;
            $user->save();

            return response()->json([
                'message' => 'Usuario activado exitosamente',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'active' => $user->active
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al activar usuario: ' . $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    /**
     * Revocar todos los tokens de un usuario (solo super admin)
     */
    public function revokeAllTokens(Request $request, $userId)
    {
        if (!$request->user()->hasRole('super_admin')) {
            return response()->json([
                'message' => 'No tienes permisos para revocar tokens',
                'status' => 'error'
            ], 403);
        }

        try {
            $user = User::findOrFail($userId);
            $tokensDeleted = $user->tokens()->count();
            $user->tokens()->delete();

            return response()->json([
                'message' => 'Tokens revocados exitosamente',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email
                ],
                'tokens_revoked' => $tokensDeleted
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al revocar tokens: ' . $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    /**
     * Ejecutar seeder completo de roles y permisos automáticamente
     */
    private function runRolesAndPermissionsSeeder()
    {
        // Instanciar el seeder y ejecutarlo directamente sin setCommand
        $seeder = new RolesAndPermissionsSeeder();
        
        // Ejecutar solo la creación de permisos y roles, no usuarios por defecto
        $seeder->runPermissionsAndRolesOnly();
    }

    /**
     * Obtener información del sistema
     */
    public function systemInfo()
    {
        $userCount = User::count();
        $isInitialized = $userCount > 0;

        return response()->json([
            'system_initialized' => $isInitialized,
            'user_count' => $userCount,
            'roles_count' => Role::count(),
            'app_name' => config('app.name'),
            'app_env' => config('app.env'),
            'app_debug' => config('app.debug'),
            'database_connected' => $this->checkDatabaseConnection(),
        ]);
    }

    /**
     * Verificar conexión a la base de datos
     */
    private function checkDatabaseConnection(): bool
    {
        try {
            \DB::connection()->getPdo();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}