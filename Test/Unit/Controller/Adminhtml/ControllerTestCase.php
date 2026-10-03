<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Controller\Adminhtml;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Shared admin controller wiring that records redirects and flash messages.
 */
abstract class ControllerTestCase extends TestCase
{
    protected array $redirect = [];
    protected array $messages = [];
    protected array $aclChecks = [];

    protected function context(array $params = [], array $post = [], array $files = [], bool $allowed = true): Context
    {
        $this->redirect = [];
        $this->messages = ['success' => [], 'error' => [], 'warning' => [], 'notice' => []];
        $this->aclChecks = [];

        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(
            static fn($key, $default = null) => $params[$key] ?? $default
        );
        $request->method('getPostValue')->willReturn($post);
        $request->method('getFiles')->willReturnCallback(static fn($key = null) => $files[$key] ?? null);

        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(
            function ($path, $args = []) use (&$redirect) {
                $this->redirect = ['path' => $path, 'params' => $args];
                return $redirect;
            }
        );
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $messageManager = $this->createStub(ManagerInterface::class);
        foreach (array_keys($this->messages) as $type) {
            $messageManager->method('add' . ucfirst($type) . 'Message')->willReturnCallback(
                function ($message) use ($type, &$messageManager) {
                    $this->messages[$type][] = (string) $message;
                    return $messageManager;
                }
            );
        }

        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(function ($resource) use ($allowed) {
            $this->aclChecks[] = $resource;
            return $allowed;
        });

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getMessageManager')->willReturn($messageManager);
        $context->method('getAuthorization')->willReturn($authorization);

        return $context;
    }
}
