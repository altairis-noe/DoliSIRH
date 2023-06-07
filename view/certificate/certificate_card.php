<?php
/* Copyright (C) 2021-2023 EVARISK <technique@evarisk.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    view/certificate/certificate_card.php
 * \ingroup dolisirh
 * \brief   Page to create/edit/view certificate.
 */

// Load DoliSIRH environment.
if (file_exists('../../dolisirh.main.inc.php')) {
    require_once __DIR__ . '/../../dolisirh.main.inc.php';
} elseif (file_exists('../../../dolisirh.main.inc.php')) {
    require_once __DIR__ . '/../../../dolisirh.main.inc.php';
} else {
    die('Include of dolisirh main fails');
}

// Load Saturne Libraries.
require_once __DIR__ . '/../../../saturne/class/saturnesignature.class.php';

// load DoliSIRH libraries.
require_once __DIR__ . '/../../class/certificate.class.php';
require_once __DIR__ . '/../../class/dolisirhdocuments/certificatedocument.class.php';
require_once __DIR__ . '/../../lib/dolisirh_certificate.lib.php';

// Global variables definitions.
global $conf, $db, $hookmanager, $langs, $mysoc, $user;

// Load translation files required by the page.
saturne_load_langs();

// Get parameters.
$id                  = GETPOST('id', 'int');
$ref                 = GETPOST('ref', 'alpha');
$action              = GETPOST('action', 'aZ09');
$confirm             = GETPOST('confirm', 'alpha');
$cancel              = GETPOST('cancel', 'aZ09');
$contextpage         = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : 'certificatecard'; // To manage different context of search
$backtopage          = GETPOST('backtopage', 'alpha');
$backtopageforcancel = GETPOST('backtopageforcancel', 'alpha');

// Initialize technical objects.
$object              = new Certificate($db);
$signatory           = new SaturneSignature($db);
$certificatedocument = new CertificateDocument($db);
$extrafields         = new ExtraFields($db);

// Initialize view objects.
$form = new Form($db);

$hookmanager->initHooks(['certificatecard', 'globalcard']); // Note that conf->hooks_modules contains array.

// Fetch optionals attributes and labels.
$extrafields->fetch_name_optionals_label($object->table_element);

$search_array_options = $extrafields->getOptionalsFromPost($object->table_element, '', 'search_');

// Initialize array of search criterias.
$search_all = GETPOST('search_all', 'alpha');
$search = [];
foreach ($object->fields as $key => $val) {
    if (GETPOST('search_'.$key, 'alpha')) {
        $search[$key] = GETPOST('search_'.$key, 'alpha');
    }
}

if (empty($action) && empty($id) && empty($ref)) {
    $action = 'view';
}

// Load object
include DOL_DOCUMENT_ROOT . '/core/actions_fetchobject.inc.php'; // Must be included, not include_once.

$upload_dir = $conf->dolisirh->multidir_output[$object->entity ?? 1];

// Security check - Protection if external user
$permissiontoread   = $user->rights->dolisirh->certificate->read;
$permissiontoadd    = $user->rights->dolisirh->certificate->write;
$permissiontodelete = $user->rights->dolisirh->certificate->delete || ($permissiontoadd && isset($object->status) && $object->status == SaturneCertificate::STATUS_DRAFT);
saturne_check_access($permissiontoread);

/*
 * Actions
 */

$parameters = [];
$reshook    = $hookmanager->executeHooks('doActions', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks.
if ($reshook < 0) {
    setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
    $error = 0;

    $backurlforlist = dol_buildpath('/dolisirh/view/certificate/certificate_list.php', 1);

    if (empty($backtopage) || ($cancel && empty($id))) {
        if (empty($backtopage) || ($cancel && strpos($backtopage, '__ID__'))) {
            if (empty($id) && (($action != 'add' && $action != 'create') || $cancel)) {
                $backtopage = $backurlforlist;
            } else {
                $backtopage = dol_buildpath('/dolisirh/view/certificate/certificate_card.php', 1) . '?id=' . ($id > 0 ? $id : '__ID__');
            }
        }
    }

    // Actions cancel, add, update, update_extras, confirm_validate, confirm_delete, confirm_deleteline, confirm_clone, confirm_close, confirm_setdraft, confirm_reopen.
    $conf->global->MAIN_DISABLE_PDF_AUTOUPDATE = 1;
    include DOL_DOCUMENT_ROOT . '/core/actions_addupdatedelete.inc.php';

    // Action to build doc.
    if (($action == 'builddoc' || GETPOST('forcebuilddoc')) && $permissiontoadd) {
        $outputlangs = $langs;
        $newlang     = '';

        if ($conf->global->MAIN_MULTILANGS && empty($newlang) && GETPOST('lang_id', 'aZ09')) {
            $newlang = GETPOST('lang_id', 'aZ09');
        }
        if (!empty($newlang)) {
            $outputlangs = new Translate('', $conf);
            $outputlangs->setDefaultLang($newlang);
        }

        // To be sure vars is defined
        if (empty($hidedetails)){
            $hidedetails = 0;
        }
        if (empty($hidedesc)) {
            $hidedesc = 0;
        }
        if (empty($hideref)) {
            $hideref = 0;
        }
        if (empty($moreparams)) {
            $moreparams = null;
        }

        if (GETPOST('forcebuilddoc')) {
            $model  = '';
            $modellist = saturne_get_list_of_models($db, $object->element . 'document');
            if (!empty($modellist)) {
                asort($modellist);
                $modellist = array_filter($modellist, 'saturne_remove_index');
                if (is_array($modellist)) {
                    $models = array_keys($modellist);
                }
            }
        } else {
            $model = GETPOST('model', 'alpha');
        }

        $moreparams['object'] = $object;
        $moreparams['user']   = $user;

        if ($object->status < SaturneCertificate::STATUS_ARCHIVED) {
            $moreparams['specimen'] = 1;
            $moreparams['zone']     = 'private';
        } else {
            $moreparams['specimen'] = 0;
        }

        $result = $certificatedocument->generateDocument((!empty($models) ? $models[0] : $model), $outputlangs, $hidedetails, $hidedesc, $hideref, $moreparams);
        if ($result <= 0) {
            setEventMessages($certificatedocument->error, $certificatedocument->errors, 'errors');
            $action = '';
        } elseif (empty($donotredirect)) {
            setEventMessages($langs->trans('FileGenerated') . ' - ' . $certificatedocument->last_main_doc, []);
            $urltoredirect = $_SERVER['REQUEST_URI'];
            $urltoredirect = preg_replace('/#builddoc$/', '', $urltoredirect);
            $urltoredirect = preg_replace('/action=builddoc&?/', '', $urltoredirect); // To avoid infinite loop
            $urltoredirect = preg_replace('/forcebuilddoc=1&?/', '', $urltoredirect); // To avoid infinite loop
            header('Location: ' . $urltoredirect . '#builddoc');
            exit;
        }
    }

    // Delete file in doc form.
    if ($action == 'remove_file' && $permissiontodelete) {
        if (!empty($upload_dir)) {
            require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

            $langs->load('other');
            $fileToDelete = GETPOST('file', 'alpha');
            $file         = $upload_dir . '/' . $fileToDelete;
            $ret          = dol_delete_file($file, 0, 0, 0, $object);
            if ($ret) {
                setEventMessages($langs->trans('FileWasRemoved', $fileToDelete), []);
            } else {
                setEventMessages($langs->trans('ErrorFailToDeleteFile', $fileToDelete), [], 'errors');
            }

            // Make a redirect to avoid to keep the remove_file into the url that create side effects.
            $urltoredirect = $_SERVER['REQUEST_URI'];
            $urltoredirect = preg_replace('/#builddoc$/', '', $urltoredirect);
            $urltoredirect = preg_replace('/action=remove_file&?/', '', $urltoredirect);

            header('Location: ' . $urltoredirect);
            exit;
        } else {
            setEventMessages('BugFoundVarUploaddirnotDefined', [], 'errors');
        }
    }

    require_once __DIR__ . '/../../../saturne/core/tpl/signature/signature_action_workflow.tpl.php';

    // Actions to send emails.
    $triggersendname = strtoupper($object->element) . '_SENTBYMAIL';
    $autocopy        = 'MAIN_MAIL_AUTOCOPY_' . strtoupper($object->element) . '_TO';
    $trackid         = $object->element . $object->id;
    include DOL_DOCUMENT_ROOT . '/core/actions_sendmails.inc.php';
}

/*
 * View
 */

$title    = $langs->trans(ucfirst($object->element));
$help_url = 'FR:Module_DoliSIRH';

saturne_header(0, '', $title, $help_url);

// Part to create.
if ($action == 'create') {
    if (empty($permissiontoadd)) {
        accessforbidden($langs->trans('NotEnoughPermissions'), 0);
        exit;
    }

    print load_fiche_titre($langs->trans('New' . ucfirst($object->element)), '', 'object_' . $object->picto);

    print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="add">';
    if ($backtopage) {
        print '<input type="hidden" name="backtopage" value="' . $backtopage . '">';
    }
    if ($backtopageforcancel) {
        print '<input type="hidden" name="backtopageforcancel" value="' . $backtopageforcancel . '">';
    }

    print dol_get_fiche_head();

    print '<table class="border centpercent tableforfieldcreate">';

    $object->fields['fk_project']['default'] = $conf->global->DOLISIRH_HR_PROJECT;

    $elementList = [];
    if (!empty($conf->user->enabled)) {
        $elementList['user'] = img_picto('', 'user', 'class="pictofixedwidth"') . dol_escape_htmltag($langs->trans('User'));
    }
    if (!empty($conf->societe->enabled)) {
        $elementList['product'] .= img_picto('', 'product', 'class="pictofixedwidth"') . dol_escape_htmltag($langs->trans('Product'));
    } ?>

    print '<tr><td class="titlefieldcreate"><label for="element_type">' . $langs->trans('ElementType') . '</label></td>';
    print '<td class="valuefieldcreate">' . $form::selectarray('element_type', $elementList, GETPOSTISSET('element_type') ? GETPOST('element_type') : 'user', 1, 0, 0, '', 0, 0, 0, '', 'maxwidth200 widthcentpercentminusx') . '</td>';
    print '</tr>';
    
    <script>
    $(document).ready(function(){
        $('#element_type').on('change', function(){
            let value = $(this).val();
            let url = new URL(document.URL)
            let search_params = url.searchParams;
            search_params.set('element_type', value);
            url.search = search_params.toString();
            location.href = url.toString()
        });
    });
    </script>
<?php

    switch (GETPOST('element_type')) {
        case 'user' :
            $object->fields['fk_element']['type']  = 'integer:User:user/class/user.class.php';
            $object->fields['fk_element']['picto'] = 'user';
            break;
        case 'product' :
            $object->fields['fk_element']['type']  = 'integer:Product:product/class/product.class.php';
            $object->fields['fk_element']['picto'] = 'product';
            break;
    }

    // Common attributes
    include DOL_DOCUMENT_ROOT . '/core/tpl/commonfields_add.tpl.php';

    // Other attributes
    include DOL_DOCUMENT_ROOT . '/core/tpl/extrafields_add.tpl.php';

    print '</table>';

    print dol_get_fiche_end();

    print $form->buttonsSaveCancel('Create');

    print '</form>';
}

// Part to edit record.
if (($id || $ref) && $action == 'edit') {
    print load_fiche_titre($langs->trans('Modify' . ucfirst($object->element)), '', 'object_' . $object->picto);

    print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="update">';
    print '<input type="hidden" name="id" value="' . $object->id . '">';
    if ($backtopage) {
        print '<input type="hidden" name="backtopage" value="' . $backtopage . '">';
    }
    if ($backtopageforcancel) {
        print '<input type="hidden" name="backtopageforcancel" value="' . $backtopageforcancel . '">';
    }

    print dol_get_fiche_head();

    print '<table class="border centpercent tableforfieldedit">';

    // Common attributes.
    include DOL_DOCUMENT_ROOT . '/core/tpl/commonfields_edit.tpl.php';

    // Other attributes.
    include DOL_DOCUMENT_ROOT . '/core/tpl/extrafields_edit.tpl.php';

    print '</table>';

    print dol_get_fiche_end();

    print $form->buttonsSaveCancel();

    print '</form>';
}

// Part to show record.
if ($object->id > 0 && (empty($action) || ($action != 'edit' && $action != 'create'))) {
    $res = $object->fetch_optionals();

    saturne_get_fiche_head($object, 'card', $title);
    saturne_banner_tab($object);

    $formconfirm = '';

    // setDraft confirmation
    if (($action == 'draft' && (empty($conf->use_javascript_ajax) || !empty($conf->dol_use_jmobile))) || (!empty($conf->use_javascript_ajax) && empty($conf->dol_use_jmobile))) {
        $formconfirm .= $form->formconfirm($_SERVER['PHP_SELF'] . '?id=' . $object->id . '&object_type=' . $object->element, $langs->trans('ReOpenObject', $langs->transnoentities('The' . ucfirst($object->element))), $langs->trans('ConfirmReOpenObject', $langs->transnoentities('The' . ucfirst($object->element)), $langs->transnoentities('The' . ucfirst($object->element))), 'confirm_setdraft', '', 'yes', 'actionButtonInProgress', 350, 600);
    }
    // setPendingSignature confirmation
    if (($action == 'pending_signature' && (empty($conf->use_javascript_ajax) || !empty($conf->dol_use_jmobile))) || (!empty($conf->use_javascript_ajax) && empty($conf->dol_use_jmobile))) {
        $formconfirm .= $form->formconfirm($_SERVER['PHP_SELF'] . '?id=' . $object->id . '&object_type=' . $object->element, $langs->trans('ValidateObject', $langs->transnoentities('The' . ucfirst($object->element))), $langs->trans('ConfirmValidateObject', $langs->transnoentities('The' . ucfirst($object->element)), $langs->transnoentities('The' . ucfirst($object->element))), 'confirm_validate', '', 'yes', 'actionButtonPendingSignature', 350, 600);
    }

    // Confirmation to delete.
    if ($action == 'delete') {
        $formconfirm = $form->formconfirm($_SERVER['PHP_SELF'] . '?id=' . $object->id, $langs->trans('DeleteObject', $langs->transnoentities('The' . ucfirst($object->element))), $langs->trans('ConfirmDeleteObject', $langs->transnoentities('The' . ucfirst($object->element))), 'confirm_delete', '', 'yes', 1);
    }

    // Call Hook formConfirm
    $parameters = ['formConfirm' => $formconfirm];
    $reshook = $hookmanager->executeHooks('formConfirm', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
    if (empty($reshook)) {
        $formconfirm .= $hookmanager->resPrint;
    } elseif ($reshook > 0) {
        $formconfirm = $hookmanager->resPrint;
    }

    // Print form confirm
    print $formconfirm;

    $mesg              = '';
    $nbAttendantByRole = [];
    $nbAttendants      = 0;
    $attendantsRole    = ['Attendant'];
    foreach ($attendantsRole as $attendantRole) {
        $signatories = $signatory->fetchSignatory($attendantRole, $object->id, $object->element);
        if (is_array($signatories) && !empty($signatories)) {
            foreach ($signatories as $objectSignatory) {
                if ($objectSignatory->role == $attendantRole) {
                    $nbAttendantByRole[$attendantRole]++;
                }
            }
        } else {
            $nbAttendantByRole[$attendantRole] = 0;
        }
        if ($nbAttendantByRole[$attendantRole] == 0) {
            $mesg .= $langs->trans('NoAttendant', $langs->trans($attendantRole), $langs->transnoentities('The' . ucfirst($object->element))) . '<br>';
        }
    }

    if (!in_array(0, $nbAttendantByRole)) {
        $nbAttendants = 1;
    }

    print '<div class="fichecenter">';
    print '<div class="fichehalfleft">';
    print '<table class="border centpercent tableforfield">';

    unset($object->fields['label']);      // Hide field already shown in banner
    unset($object->fields['fk_soc']);     // Hide field already shown in banner
    unset($object->fields['fk_project']); // Hide field already shown in banner

    // Common attributes.
    include DOL_DOCUMENT_ROOT . '/core/tpl/commonfields_view.tpl.php';

    // Other attributes. Fields from hook formObjectOptions and Extrafields.
    include DOL_DOCUMENT_ROOT . '/core/tpl/extrafields_view.tpl.php';

    print '</table>';
    print '</div>';
    print '</div>';

    print '<div class="clearboth"></div>';

    print dol_get_fiche_end();

    // Buttons for actions.
    if ($action != 'presend' ) {
        print '<div class="tabsAction">';
        $parameters = [];
        $reshook = $hookmanager->executeHooks('addMoreActionsButtons', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
        if ($reshook < 0) {
            setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
        }

        if (empty($reshook) && $permissiontoadd) {
            // Modify
            if ($object->status == SaturneCertificate::STATUS_DRAFT) {
                print '<a class="butAction" id="actionButtonEdit" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=edit' . '"><i class="fas fa-edit"></i> ' . $langs->trans('Modify') . '</a>';
            } else {
                print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag($langs->trans('ObjectMustBeDraft', ucfirst($langs->transnoentities('The' . ucfirst($object->element))))) . '"><i class="fas fa-edit"></i> ' . $langs->trans('Modify') . '</span>';
            }

            // Validate
            if ($object->status == SaturneCertificate::STATUS_DRAFT && $nbAttendants > 0) {
                print '<span class="butAction" id="actionButtonPendingSignature"><i class="fas fa-check"></i> ' . $langs->trans('Validate') . '</span>';
            } else {
                print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag($langs->trans('ObjectMustBeDraft', ucfirst($langs->transnoentities('The' . ucfirst($object->element)))) . '<br>' . $mesg) . '"><i class="fas fa-check"></i> ' . $langs->trans('Validate') . '</span>';
            }

            // ReOpen
            if ($object->status == SaturneCertificate::STATUS_VALIDATED) {
                print '<span class="butAction" id="actionButtonInProgress"><i class="fas fa-lock-open"></i> ' . $langs->trans('ReOpenDoli') . '</span>';
            } else {
                print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag($langs->trans('ObjectMustBeValidated', ucfirst($langs->transnoentities('The' . ucfirst($object->element))))) . '"><i class="fas fa-lock-open"></i> ' . $langs->trans('ReOpenDoli') . '</span>';
            }

            // Sign
            if ($object->status == SaturneCertificate::STATUS_VALIDATED && !$signatory->checkSignatoriesSignatures($object->id, $object->element)) {
                print '<a class="butAction" id="actionButtonSign" href="' . dol_buildpath('/custom/saturne/view/saturne_attendants.php?id=' . $object->id . '&module_name=DoliSIRH&object_type=' . $object->element, 3) . '"><i class="fas fa-signature"></i> ' . $langs->trans('Sign') . '</a>';
            } else {
                print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag($langs->trans('ObjectMustBeValidatedToSign', ucfirst($langs->transnoentities('The' . ucfirst($object->element))))) . '"><i class="fas fa-signature"></i> ' . $langs->trans('Sign') . '</span>';
            }

            // Send mail
            if ($object->status >= SaturneCertificate::STATUS_VALIDATED && $signatory->checkSignatoriesSignatures($object->id, $object->element)) {
                $fileparams = dol_most_recent_file($upload_dir . '/' . $object->element . 'document' . '/' . $object->ref);
                $file       = $fileparams['fullname'];
                if (file_exists($file) && !preg_match('/specimen/', $fileparams['name'])) {
                    $forcebuilddoc = 0;
                } else {
                    $forcebuilddoc = 1;
                }
                print '<a class="butAction" id="actionButtonSign" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=presend&forcebuilddoc=' . $forcebuilddoc . '&mode=init#formmailbeforetitle' . '"><i class="fas fa-paper-plane"></i> ' .  $langs->trans('SendMail') . '</a>';
            } else {
                print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag($langs->trans('ObjectMustBeLockedToSendEmail', ucfirst($langs->transnoentities('The' . ucfirst($object->element))))) . '"><i class="fas fa-paper-plane"></i> ' . $langs->trans('SendMail') . '</span>';
            }

            // Archive
            if ($object->status >= SaturneCertificate::STATUS_VALIDATED) {
                print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=confirm_archive&token=' . newToken() . '"><i class="fas fa-archive"></i> ' . $langs->trans('Archive') . '</a>';
            } else {
                print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag($langs->trans('ObjectMustBeLockedToArchive', ucfirst($langs->transnoentities('The' . ucfirst($object->element))))) . '"><i class="fas fa-archive"></i> ' . $langs->trans('Archive') . '</span>';
            }

            // Delete (need delete permission, or if draft, just need create/modify permission).
            print dolGetButtonAction('<i class="fas fa-trash"></i> ' . $langs->trans('Delete'), '', 'delete', $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=delete', '', $permissiontodelete || ($object->status == SaturneCertificate::STATUS_DRAFT));
        }
        print '</div>';
    }

    // Select mail models is same action as presend
    if (GETPOST('modelselected')) {
        $action = 'presend';
    }

    if ($action != 'presend') {
        print '<div class="fichecenter"><div class="fichehalfleft">';
        // Documents
        $objRef    = dol_sanitizeFileName($object->ref);
        $dirFiles  = $object->element . 'document/' . $objRef;
        $fileDir   = $upload_dir . '/' . $dirFiles;
        $urlSource = $_SERVER['PHP_SELF'] . '?id=' . $object->id;

        print saturne_show_documents('dolisirh:' . ucfirst($object->element) . 'Document', $dirFiles, $fileDir, $urlSource, $permissiontoadd, $permissiontodelete, $conf->global->DOLISIRH_CERTIFICATEDOCUMENT_DEFAULT_MODEL, 1, 0, 0, 0, 0, '', '', '', $langs->defaultlang, $object, 0, 'remove_file', ($object->status > SaturneCertificate::STATUS_DRAFT && $nbAttendants > 0), $langs->trans('ObjectMustBeValidatedToGenerate'));

        print '</div><div class="fichehalfright">';

        $MAXEVENT = 10;

        if ($permissiontoread) {
            $morehtmlcenter = dolGetButtonTitle($langs->trans('SeeAll'), '', 'fa fa-bars imgforviewmode', dol_buildpath('/saturne/view/saturne_agenda.php', 1) . '?id=' . $object->id . '&module_name=DoliSIRH&object_type=' . $object->element);
        } else {
            $morehtmlcenter = '';
        }

        // List of actions on element.
        include_once DOL_DOCUMENT_ROOT . '/core/class/html.formactions.class.php';
        $formactions    = new FormActions($db);
        $somethingshown = $formactions->showactions($object, $object->element . '@' . $object->module, '', 1, '', $MAXEVENT, '', $morehtmlcenter);

        print '</div></div>';
    }

    // Presend form.
    $modelmail    = 'certificate';
    $defaulttopic = 'InformationMessage';
    $diroutput    = $conf->dolisirh->dir_output;
    $trackid      = 'certificate'. $object->id;

    include DOL_DOCUMENT_ROOT . '/core/tpl/card_presend.tpl.php';
}

// End of page
llxFooter();
$db->close();
