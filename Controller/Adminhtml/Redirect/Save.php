<?php
declare(strict_types=1);

namespace Panth\Redirects\Controller\Adminhtml\Redirect;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\Redirects\Api\Data\RedirectRuleInterface;
use Panth\Redirects\Controller\Adminhtml\AbstractAction;
use Panth\Redirects\Model\Config\Source\StatusCode;
use Panth\Redirects\Model\Redirect\Loop;

/**
 * Save a redirect row. POST-only, FormKey validated.
 *
 * SECURITY
 * --------
 *  - Every request parameter is cast to its expected scalar type before
 *    use.
 *  - Allow-lists are applied to match_type and status_code — arbitrary
 *    values would otherwise stick in the DB and break the Matcher.
 *  - The target column is checked for dangerous URI schemes and rejected
 *    before any DB write.
 *  - For literal rules, the loop detector runs so an admin can't create
 *    a redirect chain that would trap the Matcher.
 */
class Save extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_Redirects::redirects';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly Loop $loopDetector
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $data = (array) $this->getRequest()->getPostValue();
        $resultRedirect = $this->resultRedirectFactory->create();
        if (!$data) {
            return $resultRedirect->setPath('*/*/');
        }

        $id = (int) ($data['redirect_id'] ?? 0);
        $matchType = (string) ($data['match_type'] ?? RedirectRuleInterface::MATCH_LITERAL);
        if (!in_array($matchType, [
            RedirectRuleInterface::MATCH_LITERAL,
            RedirectRuleInterface::MATCH_REGEX,
            RedirectRuleInterface::MATCH_MAINTENANCE,
        ], true)) {
            $this->messageManager->addErrorMessage(__('Invalid match type.'));
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }

        $statusCode = (int) ($data['status_code'] ?? 301);
        if (!in_array($statusCode, StatusCode::ALLOWED, true)) {
            $this->messageManager->addErrorMessage(__('Invalid status code.'));
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }

        $target = trim((string) ($data['target'] ?? ''));
        if (@preg_match('#^(javascript|data|vbscript):#i', $target)) {
            $this->messageManager->addErrorMessage(__('Target URL must not use javascript:, data:, or vbscript: protocols.'));
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }

        $pattern = trim((string) ($data['pattern'] ?? ''));
        if ($pattern === '' || $target === '') {
            $this->messageManager->addErrorMessage(__('Pattern and Target URL are required.'));
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }

        // Validate regex actually compiles — a bad regex would otherwise be
        // silently skipped at runtime by the Matcher.
        if ($matchType === RedirectRuleInterface::MATCH_REGEX) {
            $wrapped = '~' . str_replace('~', '\\~', $pattern) . '~';
            try {
                $testResult = @preg_match($wrapped, '');
            } catch (\Throwable) {
                $testResult = false;
            }
            if ($testResult === false) {
                $this->messageManager->addErrorMessage(__('Pattern is not a valid regular expression.'));
                return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
            }
        }

        $storeId = (int) ($data['store_id'] ?? 0);

        if ($matchType === RedirectRuleInterface::MATCH_LITERAL) {
            $loop = $this->loopDetector->detect($pattern, $target, $storeId, $id > 0 ? $id : null);
            if (!empty($loop)) {
                $this->messageManager->addErrorMessage(
                    __('Redirect loop detected: %1', implode(' -> ', $loop))
                );
                return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
            }
        }

        $startAt  = !empty($data['start_at'])  ? (string) $data['start_at']  : null;
        $finishAt = !empty($data['finish_at']) ? (string) $data['finish_at'] : null;

        $row = [
            'pattern'     => mb_substr($pattern, 0, 1024),
            'target'      => mb_substr($target, 0, 1024),
            'match_type'  => $matchType,
            'status_code' => $statusCode,
            'store_id'    => $storeId,
            'is_active'   => (int) ($data['is_active'] ?? 1),
            'priority'    => (int) ($data['priority'] ?? 10),
            'start_at'    => $startAt,
            'finish_at'   => $finishAt,
            'updated_at'  => $this->dateTime->gmtDate(),
        ];

        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_redirect');
            if ($id > 0) {
                $connection->update($table, $row, ['redirect_id = ?' => $id]);
            } else {
                $row['created_at'] = $this->dateTime->gmtDate();
                $connection->insert($table, $row);
                $id = (int) $connection->lastInsertId($table);
            }
            $this->messageManager->addSuccessMessage(__('Redirect saved.'));
            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }

        return $resultRedirect->setPath('*/*/');
    }
}
