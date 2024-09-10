<?php

/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\MailChangePasswordDirectadminPlugin;

/**
 * Allows users to change passwords on their email accounts in Directadmin.
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2023, Afterlogic Corp.
 *
 * @property Settings $oModuleSettings
 *
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractModule
{
    /**
     * @var \DirectAdminPassAPI
     */
    private $oDAApi;

    public function init()
    {
        $this->subscribeEvent('Mail::Account::ToResponseArray', array($this, 'onMailAccountToResponseArray'));
        $this->subscribeEvent('Mail::ChangeAccountPassword', array($this, 'onChangeAccountPassword'));
        $this->subscribeEvent('StandardResetPassword::ChangeAccountPassword', array($this, 'onChangeAccountPassword'));

        require_once __DIR__ . '/da_api.php';
    }

    /**
     * @return Module
     */
    public static function getInstance()
    {
        return parent::getInstance();
    }

    /**
     * @return Module
     */
    public static function Decorator()
    {
        return parent::Decorator();
    }

    /**
     * @return Settings
     */
    public function getModuleSettings()
    {
        return $this->oModuleSettings;
    }

    /**
     * Adds to account response array information about if allowed to change the password for this account.
     * @param array $aArguments
     * @param mixed $mResult
     */
    public function onMailAccountToResponseArray($aArguments, &$mResult)
    {
        $oAccount = $aArguments['Account'];

        if ($oAccount && $this->checkCanChangePassword($oAccount)) {
            if (!isset($mResult['Extend']) || !is_array($mResult['Extend'])) {
                $mResult['Extend'] = [];
            }
            $mResult['Extend']['AllowChangePasswordOnMailServer'] = true;
        }
    }

    /**
     * Tries to change password for account if allowed.
     * @param array $aArguments
     * @param mixed $mResult
     */
    public function onChangeAccountPassword($aArguments, &$mResult)
    {
        $bPasswordChanged = false;
        $bBreakSubscriptions = false;

        $oAccount = $aArguments['Account'];
        if ($oAccount && $this->checkCanChangePassword($oAccount) && ($oAccount->getPassword() === $aArguments['CurrentPassword']
                || isset($aArguments['SkipCurrentPasswordCheck']) && $aArguments['SkipCurrentPasswordCheck'])) {
            $bPasswordChanged = $this->changePassword($oAccount, $aArguments['NewPassword']);
            $bBreakSubscriptions = true; // break if mail server plugin tries to change password in this account.
        }

        if (is_array($mResult)) {
            $mResult['AccountPasswordChanged'] = $mResult['AccountPasswordChanged'] || $bPasswordChanged;
        }

        return $bBreakSubscriptions;
    }

    /**
     * Checks if allowed to change password for account.
     * @param \Aurora\Modules\Mail\Models\MailAccount $oAccount
     * @return bool
     */
    protected function checkCanChangePassword($oAccount)
    {
        $bFound = in_array('*', $this->oModuleSettings->SupportedServers);

        if (!$bFound) {
            $oServer = $oAccount->getServer();

            if ($oServer && in_array($oServer->IncomingServer, $this->oModuleSettings->SupportedServers)) {
                $bFound = true;
            }
        }

        return $bFound;
    }

    /**
     * Tries to change password for account.
     * @param \Aurora\Modules\Mail\Models\MailAccount $oAccount
     * @param string $sPassword
     * @return boolean
     * @throws \Aurora\System\Exceptions\ApiException
     */
    protected function changePassword($oAccount, $sPassword)
    {
        $bResult = false;

        $sDomain = \MailSo\Base\Utils::GetDomainFromEmail($oAccount->IncomingLogin);
        $oServer = false;
        $aGetMailServerResult = \Aurora\Modules\Mail\Module::Decorator()->GetMailServerByDomain($sDomain, /*AllowWildcardDomain*/false);

        if (!empty($aGetMailServerResult) && isset($aGetMailServerResult['Server'])) {

            $oServer = $aGetMailServerResult['Server'];
            $sHost = $oServer->getAttributes()['IncomingServer'];
            
            $sPassCurr = $oAccount->getPassword();
            if (0 < strlen($sPassCurr) && $sPassCurr !== $sPassword) {
                $this->oDAApi = new \DirectAdminPassAPI('https://' . $sHost . ':2222');
                $bResult = $this->oDAApi->CMD_CHANGE_EMAIL_PASSWORD(
                    $oAccount->IncomingLogin, $sPassCurr, $sPassword, $sPassword
                );

                if (!$bResult) {
                    throw new \Aurora\System\Exceptions\ApiException(\Aurora\System\Exceptions\Errs::UserManager_AccountNewPasswordUpdateError);
                }
            }

        } else {
            throw new \Aurora\System\Exceptions\ApiException(\Aurora\System\Exceptions\Errs::UserManager_AccountNewPasswordUpdateError);
        }
        return $bResult;

    }
}
