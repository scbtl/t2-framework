<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace app\exception;

use Throwable;
use t2\http\Request;
use t2\http\Response;

class MissingInputException extends PageNotFoundException
{
    /**
     * @var string
     */
    protected string $template = '/app/view/400';

    /**
     * MissingInputException constructor.
     * @param string $message
     * @param int $code
     * @param Throwable|null $previous
     */
    public function __construct(string $message = 'Missing input parameter :parameter', int $code = 400, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Render an exception into an HTTP response.
     * @param Request $request
     * @return Response|null
     * @throws Throwable
     */
    public function render(Request $request): ?Response
    {
        $code = $this->getCode() ?: 404;
        $debug = config($request->plugin ? "plugin.$request->plugin.app.debug" : 'app.debug');
        $data = $debug ? $this->data : ['parameter' => ''];
        $message = $this->trans($this->getMessage(), $data);
        if ($request->expectsJson()) {
            $json = ['code' => $code, 'msg' => $message, 'data' => $data];
            return new Response(200, ['Content-Type' => 'application/json'], json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return new Response($code, [], $this->html($message));
    }
}