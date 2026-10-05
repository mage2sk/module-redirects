<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Block;

use Magento\Framework\App\Request\Http;
use Magento\Framework\UrlInterface;
use Panth\Redirects\Block\Adminhtml\GenericBackButton;
use Panth\Redirects\Block\Adminhtml\GenericDeleteButton;
use PHPUnit\Framework\TestCase;

class ButtonsTest extends TestCase
{
    private function url(): UrlInterface
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn($route, $params = []) => 'https://admin.test/' . $route . ($params ? '?' . http_build_query($params) : '')
        );
        return $url;
    }

    private function request(int $id): Http
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(static fn($key) => $key === 'id' ? $id : null);
        $request->method('getRouteName')->willReturn('panth_redirects');
        $request->method('getControllerName')->willReturn('redirect');
        return $request;
    }

    public function testBackButtonPointsToTheGrid(): void
    {
        $data = (new GenericBackButton($this->url()))->getButtonData();

        $this->assertSame("location.href = 'https://admin.test/*/*/';", $data['on_click']);
        $this->assertSame('back', $data['class']);
    }

    public function testDeleteButtonIsHiddenForNewRecords(): void
    {
        $this->assertSame([], (new GenericDeleteButton($this->url(), $this->request(0)))->getButtonData());
    }

    public function testDeleteButtonTargetsTheCurrentControllersDeleteAction(): void
    {
        $data = (new GenericDeleteButton($this->url(), $this->request(14)))->getButtonData();

        $this->assertStringContainsString("'https://admin.test/panth_redirects/redirect/delete?id=14'", $data['on_click']);
        $this->assertStringStartsWith('deleteConfirm(', $data['on_click']);
        $this->assertSame(20, $data['sort_order']);
    }
}
