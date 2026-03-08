<?php
/**
 * SC Giftcard Repair - PrestaShop 8 Module
 *
 * @author    Scriptami
 * @copyright Scriptami
 * @license   Academic Free License version 3.0
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

use ScGiftcardRepair\Traits\HaveScriptamiTab;

class sc_giftcard_repair extends Module
{
    use HaveScriptamiTab;

    public const VERSION = '1.0.0';

    public function __construct()
    {
        $this->name = 'sc_giftcard_repair';
        $this->version = self::VERSION;
        $this->author = 'Scriptami';
        $this->tab = 'administration';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => '8.99.99',
        ];

        parent::__construct();

        $this->displayName = $this->trans('SC Giftcard Repair', [], 'Modules.Scgiftcardrepair.Admin');
        $this->description = $this->trans(
            'Diagnostic and repair tool for thegiftcard module data anomalies.',
            [],
            'Modules.Scgiftcardrepair.Admin'
        );
    }

    /**
     * Install module
     */
    public function install(): bool
    {
        return parent::install()
            && $this->installScriptamiTab();
    }

    /**
     * Uninstall module
     */
    public function uninstall(): bool
    {
        return $this->uninstallScriptamiTab()
            && parent::uninstall();
    }

    /**
     * Check if module is active
     */
    public static function checkModuleStatus(): bool
    {
        $module = Module::getInstanceByName('sc_giftcard_repair');

        return $module instanceof Module && (bool) $module->active;
    }
}
