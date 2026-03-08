<?php
/**
 * SC Giftcard Repair - PrestaShop 8 Module
 *
 * @author    Scriptami
 * @copyright Scriptami
 * @license   Academic Free License version 3.0
 */

declare(strict_types=1);

namespace ScGiftcardRepair\Controller\Admin;

use Module;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Security\Annotation\AdminSecurity;
use ScGiftcardRepair\Service\GiftCardFixer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Admin controller for gift card repair operations
 */
class GiftCardRepairController extends FrameworkBundleAdminController
{
    private GiftCardFixer $fixer;
    private CsrfTokenManagerInterface $csrfTokenManager;

    public function __construct(
        GiftCardFixer $fixer,
        CsrfTokenManagerInterface $csrfTokenManager
    ) {
        $this->fixer = $fixer;
        $this->csrfTokenManager = $csrfTokenManager;
    }

    /**
     * Main index page: diagnostic overview or warning if thegiftcard is not installed
     *
     * @AdminSecurity(
     *     "is_granted('read', request.get('_legacy_controller'))",
     *     message="You do not have permission to access this.",
     *     redirectRoute="admin_dashboard"
     * )
     */
    public function indexAction(): Response
    {
        $giftcardInstalled = Module::isInstalled('thegiftcard');

        if (!$giftcardInstalled) {
            return $this->render(
                '@Modules/sc_giftcard_repair/views/templates/admin/index.html.twig',
                [
                    'layoutTitle' => $this->trans('Giftcard Repair', 'Modules.Scgiftcardrepair.Admin'),
                    'enableSidebar' => true,
                    'help_link' => false,
                    'giftcard_installed' => false,
                ]
            );
        }

        $diagnosis = $this->fixer->diagnose();

        return $this->render(
            '@Modules/sc_giftcard_repair/views/templates/admin/index.html.twig',
            [
                'layoutTitle' => $this->trans('Giftcard Repair', 'Modules.Scgiftcardrepair.Admin'),
                'enableSidebar' => true,
                'help_link' => false,
                'giftcard_installed' => true,
                'diagnosis' => $diagnosis,
                'csrfToken' => $this->csrfTokenManager->getToken('sc_giftcard_repair_giftcard_rebuild')->getValue(),
            ]
        );
    }

    /**
     * Preview the giftcard_rebuild fix (dry-run)
     *
     * @AdminSecurity(
     *     "is_granted('read', request.get('_legacy_controller'))",
     *     message="You do not have permission to access this.",
     *     redirectRoute="admin_dashboard"
     * )
     */
    public function previewAction(Request $request): Response
    {
        $result = $this->fixer->preview('giftcard_rebuild');
        $acceptsJson = $request->headers->get('Accept') === 'application/json';

        if ($acceptsJson) {
            return new JsonResponse($result);
        }

        $response = $this->render(
            '@Modules/sc_giftcard_repair/views/templates/admin/preview.html.twig',
            [
                'layoutTitle' => $this->trans('Preview: Giftcard Rebuild', 'Modules.Scgiftcardrepair.Admin'),
                'enableSidebar' => true,
                'help_link' => false,
                'result' => $result,
                'csrfToken' => $this->csrfTokenManager->getToken('sc_giftcard_repair_giftcard_rebuild')->getValue(),
            ]
        );
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    /**
     * Apply the giftcard_rebuild fix
     *
     * @AdminSecurity(
     *     "is_granted('update', request.get('_legacy_controller'))",
     *     message="You do not have permission to modify this.",
     *     redirectRoute="admin_dashboard"
     * )
     */
    public function applyAction(Request $request): Response
    {
        $token = $request->request->get('_token');
        $expectedToken = 'sc_giftcard_repair_giftcard_rebuild';

        if (!$this->csrfTokenManager->isTokenValid(
            new \Symfony\Component\Security\Csrf\CsrfToken($expectedToken, $token)
        )) {
            $this->addFlash('error', $this->trans('Invalid CSRF token', 'Modules.Scgiftcardrepair.Admin'));

            return $this->redirectToRoute('sc_giftcard_repair_index');
        }

        $result = $this->fixer->apply('giftcard_rebuild');
        $acceptsJson = $request->headers->get('Accept') === 'application/json';

        if ($acceptsJson) {
            return new JsonResponse($result);
        }

        if ($result['success']) {
            $this->addFlash('success', $this->trans('Fix applied successfully', 'Modules.Scgiftcardrepair.Admin'));
        } else {
            $this->addFlash('error', $this->trans(
                'Error applying fix: %error%',
                'Modules.Scgiftcardrepair.Admin',
                ['%error%' => $result['error'] ?? 'Unknown']
            ));
        }

        return $this->render(
            '@Modules/sc_giftcard_repair/views/templates/admin/result.html.twig',
            [
                'layoutTitle' => $this->trans('Giftcard Repair Result', 'Modules.Scgiftcardrepair.Admin'),
                'enableSidebar' => true,
                'help_link' => false,
                'result' => $result,
            ]
        );
    }
}
