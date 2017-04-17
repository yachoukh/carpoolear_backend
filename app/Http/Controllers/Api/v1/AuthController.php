<?php

namespace STS\Http\Controllers\Api\v1;

use JWTAuth;
use STS\User;
use Illuminate\Http\Request;
use STS\Http\Controllers\Controller;
use STS\Contracts\Logic\User as UserLogic;
use STS\Contracts\Logic\Devices as DeviceLogic;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AuthController extends Controller
{
    protected $user;
    protected $userLogic;
    protected $deviceLogic;

    public function __construct(UserLogic $userLogic, DeviceLogic $devices)
    {
        $this->middleware('api.auth', ['only' => ['logout, retoken']]);
        $this->userLogic = $userLogic;
        $this->deviceLogic = $devices;
    }

    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        try {
            if (! $token = JWTAuth::attempt($credentials)) {
                return response()->json(['error' => 'invalid_credentials'], 401);
            }
        } catch (JWTException $e) {
            return response()->json(['error' => 'could_not_create_token'], 500);
        }

        $user = JWTAuth::authenticate($token);

        if ($user->banned) {
            throw new UnauthorizedHttpException('user_banned');
        }

        if (! $user->active) {
            throw new UnauthorizedHttpException('user_not_active');
        }

        // Registro mi devices
        /*
        if ($request->has('device_id') && $request->has('device_type')) {
            $data = $request->all();
            $data['session_id'] = $token;
            $this->deviceLogic->register($user, $data);
        }
        */

        return $this->response->withArray(['token' => $token]);
    }

    public function retoken(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $oldToken = $token = JWTAuth::getToken();
        } catch (TokenExpiredException $e) {
            try {
                $oldToken = JWTAuth::getToken();
                $token = JWTAuth::refresh($oldToken);
            } catch (JWTException $e) {
                throw new AccessDeniedHttpException('invalid_token');
            }
        } catch (JWTException $e) {
            throw new AccessDeniedHttpException('invalid_token');
        }
        
        $data = [
            'session_id'  => $token,
            'app_version' => $request->get('app_version'),
        ];

        $device = $this->deviceLogic->updateBySession($oldToken, $data);
        
        return $this->response->withArray(['token' => $token]);
    }

    public function logout(Request $request)
    {
        JWTAuth::parseToken()->invalidate();
        //$this->deviceLogic->delete($token);
        return response()->json('OK');
    }

    public function active($activation_token, Request $request)
    {
        $user = $this->userLogic->activeAccount($activation_token);
        if (! $user) {
            throw new BadRequestHttpException('user_not_found');
        }
        $token = JWTAuth::fromUser($user);
        /*
        if ($request->has('device_id') && $request->has('device_type')) {
            $data = $request->all();
            $data['session_id'] = $token;
            $this->deviceLogic->register($user, $data);
        }
        */
        return $this->response->withArray(['token' => $token]);
    }

    public function reset(Request $request)
    {
        $email = $request->get('email');
        if ($email) {
            $token = $this->userLogic->resetPassword($email);
            if ($token) {
                return $this->response->withArray(['data' => 'ok']);
            } else {
                throw new BadRequestHttpException('User not found');
            }
        } else {
            throw new BadRequestHttpException('E-mail not provided');
        }
    }

    public function changePasswod($token, Request $request)
    {
        $data = $request->all();
        $status = $this->userLogic->changePassword($token, $data);
        if ($status) {
            return $this->response->withArray(['data' => 'ok']);
        } else {
            throw new UpdateResourceFailedException('Could not update user.', $this->userLogic->getErrors());
        }
    }
}
