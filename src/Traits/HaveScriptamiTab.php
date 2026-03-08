<?php
/**
 * SC Giftcard Repair - PrestaShop 8 Module
 *
 * @author    Scriptami
 * @copyright Scriptami
 * @license   Academic Free License version 3.0
 */

declare(strict_types=1);

namespace ScGiftcardRepair\Traits;

use Configuration;
use LanguageCore as Language;
use TabCore as Tab;
use ValidateCore as Validate;

/**
 * Manages the module's tab inside the shared AdminScriptami parent tab.
 *
 * The shared parent tab is registered once and shared across all Scriptami modules.
 * The list of installed Scriptami modules is stored in ps_configuration as
 * SC_SCRIPTAMI_MODULES (JSON array of module names).
 */
trait HaveScriptamiTab
{
    /**
     * Name shown in the menu for the AdminScriptami parent tab (per language)
     */
    private array $scriptamiTabNames = [
        'en' => 'Scriptami',
        'fr' => 'Scriptami',
    ];

    /**
     * Name shown in the menu for this module's child tab (per language)
     */
    private array $moduleTabNames = [
        'en' => 'Giftcard Repair',
        'fr' => 'Réparation Cartes Cadeaux',
    ];

    /**
     * Class name of this module's admin controller
     */
    private string $moduleControllerClass = 'AdminScGiftcardRepair';

    /**
     * Class name of the shared Scriptami parent tab
     */
    private string $scriptamiParentClass = 'AdminScriptami';

    /**
     * ps_configuration key that stores the list of installed Scriptami modules
     */
    private string $scriptamiModulesKey = 'SC_SCRIPTAMI_MODULES';

    /**
     * Install the module's tab inside the shared AdminScriptami parent tab
     */
    public function installScriptamiTab(): bool
    {
        $modules = $this->getScriptamiModules();
        $isFirst = empty($modules);

        if (!in_array($this->name, $modules, true)) {
            $modules[] = $this->name;
        }

        // Create parent tab only when this is the first Scriptami module
        if ($isFirst) {
            if (!$this->createParentTab()) {
                return false;
            }
        }

        // Create child tab for this module
        if (!$this->createChildTab()) {
            return false;
        }

        return $this->saveScriptamiModules($modules);
    }

    /**
     * Uninstall the module's tab and remove from the shared Scriptami list
     */
    public function uninstallScriptamiTab(): bool
    {
        // Remove child tab
        $tabId = (int) Tab::getIdFromClassName($this->moduleControllerClass);
        if ($tabId > 0) {
            $tab = new Tab($tabId);
            if (Validate::isLoadedObject($tab) && !$tab->delete()) {
                return false;
            }
        }

        // Remove from module list
        $modules = $this->getScriptamiModules();
        $modules = array_values(array_filter($modules, fn ($m) => $m !== $this->name));

        // Remove parent tab if no more Scriptami modules
        if (empty($modules)) {
            $parentId = (int) Tab::getIdFromClassName($this->scriptamiParentClass);
            if ($parentId > 0) {
                $parentTab = new Tab($parentId);
                if (Validate::isLoadedObject($parentTab)) {
                    $parentTab->delete();
                }
            }
        }

        return $this->saveScriptamiModules($modules);
    }

    /**
     * Get the list of installed Scriptami modules from ps_configuration
     *
     * @return array<string>
     */
    private function getScriptamiModules(): array
    {
        $json = Configuration::get($this->scriptamiModulesKey);
        if (empty($json)) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Save the list of installed Scriptami modules to ps_configuration
     *
     * @param array<string> $modules
     */
    private function saveScriptamiModules(array $modules): bool
    {
        return Configuration::updateValue($this->scriptamiModulesKey, json_encode(array_values($modules)));
    }

    /**
     * Create the shared AdminScriptami parent tab (only when first Scriptami module)
     */
    private function createParentTab(): bool
    {
        $existingId = (int) Tab::getIdFromClassName($this->scriptamiParentClass);
        if ($existingId > 0) {
            return true;
        }

        $idParent = (int) Tab::getIdFromClassName('AdminAdvancedParameters');
        $tabNameByLangId = $this->buildTabNamesByLangId($this->scriptamiTabNames);

        $tab = new Tab();
        $tab->module = $this->name;
        $tab->class_name = $this->scriptamiParentClass;
        $tab->position = Tab::getNewLastPosition($idParent);
        $tab->id_parent = $idParent;
        $tab->name = $tabNameByLangId;
        $tab->active = true;

        return $tab->add();
    }

    /**
     * Create the child tab for this module under AdminScriptami
     */
    private function createChildTab(): bool
    {
        $existingId = (int) Tab::getIdFromClassName($this->moduleControllerClass);
        if ($existingId > 0) {
            return true;
        }

        $parentId = (int) Tab::getIdFromClassName($this->scriptamiParentClass);
        if ($parentId === 0) {
            $parentId = -1;
        }

        $tabNameByLangId = $this->buildTabNamesByLangId($this->moduleTabNames);

        $tab = new Tab();
        $tab->module = $this->name;
        $tab->class_name = $this->moduleControllerClass;
        $tab->position = Tab::getNewLastPosition($parentId);
        $tab->id_parent = $parentId;
        $tab->name = $tabNameByLangId;
        $tab->active = true;
        $tab->wording = 'Giftcard Repair';
        $tab->wording_domain = 'Modules.Scgiftcardrepair.Admin';

        return $tab->add();
    }

    /**
     * Build a per-language-id name map from an iso-keyed array
     *
     * @param array<string, string> $namesByIso
     *
     * @return array<int, string>
     */
    private function buildTabNamesByLangId(array $namesByIso): array
    {
        $languages = Language::getLanguages(false);
        $defaultName = $namesByIso['en'] ?? reset($namesByIso);
        $byLangId = [];

        foreach ($languages as $lang) {
            $iso = $lang['iso_code'];
            $byLangId[(int) $lang['id_lang']] = $namesByIso[$iso] ?? $defaultName;
        }

        return $byLangId;
    }
}
