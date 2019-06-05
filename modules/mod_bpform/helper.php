<?php

/**
 * @package     ${package}
 *
 * @copyright   Copyright (C) ${build.year} ${copyrights},  All rights reserved.
 * @license     ${license.name}; see ${license.url}
 * @author      ${author.name}
 */

use Joomla\CMS\Captcha\Captcha;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Mail\Mail;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\Dispatcher;
use Joomla\Input\Input;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

/**
 * Helper for mod_bpform
 */
abstract class ModBPFormHelper
{

    /**
     * Form fields.
     *
     * @var array
     */
    protected static $fields;

    /**
     * Process form input.
     *
     * @param array $input_data Form input data array.
     * @param Registry $params Module parameters.
     *
     * @return bool
     *
     * @throws Exception
     */
    public static function processForm(array $input_data, Registry $params): bool
    {

        // Application instance
        $app = Factory::getApplication();

        // Submission result
        $result = true;

        // There is nothing to process, exit method
        if (empty($input_data)) {
            return true;
        }

        // Prepare data table
        $data = static::prepareAndValidateData($input_data, $params);

        // Check if every field that is required was filled
        if (in_array(false, $data, true)) {
            return false;
        }

        // Create inquiry html table
        $table = static::createTable($data, $params);

        // If user selected contact as a recipient, load contact e-mail address
        if ($params->get('recipient') === 'contact') {
            JTable::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_contact/tables');
            $contact = JTable::getInstance('Contact', 'ContactTable');
            $contact_id = $params->get('recipient_contact');

            if ($contact_id > 0 and $contact->load($contact_id) and !empty($contact->email_to) and static::isValidEmail($contact->email_to)) {
                $recipients = [$contact->email_to];
            };

            // User selected list of e-mail addresses as the recipients
        } elseif ($params->get('recipient') == 'emails') {
            $recipients = (array)$params->get('recipient_emails', []);
            $recipients = array_column($recipients, 'email');
        }

        // Admin message subject
        $subject = $params->get('admin_subject');

        // Look for client email and set a reply to field on the admin email
        $client_email = static::getClientEmail($data, $params);
        $reply_to = '';
        if (!empty($client_email)) {
            $reply_to = $client_email;
        }

        // If user did not provided e-mail addresses and debug is enabled
        if (empty($recipients) and $app->get('debug', 0)) {
            $app->enqueueMessage(Text::_('MOD_BPFORM_ERROR_NO_ADMIN_RECIPIENTS'), 'error');
            $result = false;

            // If user did not provided e-mail addresses
        } elseif (empty($recipients)) {
            $app->enqueueMessage(Text::_('MOD_BPFORM_ERROR_EMAIL_CLIENT'), 'error');
            $result = false;

            // If we failed to send email
        } elseif (!self::sendEmail($table, $subject, $recipients, $reply_to)) {
            $app->enqueueMessage(Text::_('MOD_BPFORM_ERROR_EMAIL_CLIENT'), 'error');
            $result = false;
        }


        // Send email to client if there is an email address in form
        if ($result and !empty($client_email)) {
            $body = self::prepareBody($params->get('intro', ''), $table);

            // Set reply too so user can answer the copy
            $reply_to = '';
            if (!empty($recipients)) {
                $reply_to = current($recipients);
            }

            if (!self::sendEmail($body, $params->get('client_subject', ''), [$client_email], $reply_to)) {
                $app->enqueueMessage(Text::_('MOD_BPFORM_ERROR_EMAIL_CLIENT'), 'error');
                $result = false;
            }
        }

        // If everything went fine
        if ($result) {
            $app->enqueueMessage($params->get('success_message'), 'message');
        }

        return $result;
    }

    /**
     * Convert input to array and validate data.
     *
     * @param array $input_data Input data array.
     * @param Registry $params Module parameters.
     *
     * @return array
     *
     * @throws Exception
     */
    protected static function prepareAndValidateData(array $input_data, Registry $params): array
    {

        // Applicatoin
        $app = Factory::getApplication();

        // Validate each field input
        $fields = static::getFields($params);

        $data = [];

        // Process each field
        foreach ($fields as $name => $field) {

            // If this field is required and i was not filled
            if ($field->required and (!key_exists($name, $input_data) or empty($input_data[$name]))) {
                $app->enqueueMessage(Text::sprintf('MOD_BPFORM_FIELD_S_IS_REQUIRED', $field->title), 'warning');
                $data[$name] = false;
            }

            // This field was set, so map it to data array using field title
            if (key_exists($name, $input_data)) {
                $data = array_merge($data, [$field->title => $input_data[$name]]);
            }

            // This is a checkbox so change value
            if (in_array($field->type, ['checkbox'])) {

                // If field was checked, change value to YES
                if (key_exists($name, $input_data)) {
                    $data[$field->title] = Text::_('JYES');

                    // Field wasn't check, chagne value to NO
                } else {
                    $data[$field->title] = Text::_('JNO');
                }
            }
        }

        // If captcha is enabled, validate it
        if (static::isCaptchaEnabled($params) !== false) {
            if (!static::validateCaptcha($params)) {
                $data[Text::_('MOD_BPFORM_FIELD_CAPTCHA_TITLE')] = false;
                $app->enqueueMessage(Text::sprintf('MOD_BPFORM_FIELD_CAPTCHA_ERROR'), 'warning');
            }
        }

        return $data;
    }

    /**
     * Get a list of module form fields.
     *
     * @param Registry $params Module params.
     *
     * @return array
     */
    protected static function getFields(Registry $params): array
    {

        // If fields was not processed yet
        if (is_null(static::$fields)) {
            $fields = [];

            $fields_params = (array)$params->get('fields', []);

            foreach ($fields_params as $field) {
                $fields = array_merge($fields, [$field->name => $field]);
            }
        }


        return $fields;
    }

    /**
     * Check if captcha is enabled.
     *
     * @param Registry $params Module params.
     *
     * @return mixed    Returns string if captcha is enabled or false if not.
     *
     * @throws Exception
     */
    public static function isCaptchaEnabled(Registry $params)
    {
        $app = Factory::getApplication();
        $plugin = $app->get('captcha');
        if ($app->isClient('site')) {
            $plugin = $app->getParams()->get('captcha', $plugin);
        }

        // Check if captcha is enabled
        if ($plugin === 0 || $plugin === '0' || $plugin === '' || $plugin === null || !$params->get('captcha', 0)) {
            return false;
        }

        return $plugin;
    }

    /**
     * Validate captcha response.
     *
     * @return bool
     *
     * @throws Exception
     * @var Regisry $params Module parameters.
     *
     */
    public static function validateCaptcha(Registry $params): bool
    {
        PluginHelper::importPlugin('captcha', static::isCaptchaEnabled($params));
        $dispatcher = JEventDispatcher::getInstance();
        try {
            $response = $dispatcher->trigger('onCheckAnswer');
        } catch (Exception $e) {
            $app = Factory::getApplication();
            if ($app->get('debug')) {
                $app->enqueueMessage($e->getMessage(), 'error');
            }
            $response = false;
        }

        return ($response === true) or ($response === [true]);
    }

    /**
     * Prepare data html table.
     *
     * @param array $data Form data.
     * @param Registry $params Module params
     *
     * @return string
     */
    protected static function createTable(array $data, Registry $params): string
    {
        ob_start();

        require ModuleHelper::getLayoutPath('mod_bpform', $params->get('layout', 'default') . '_table');

        return ob_get_clean();
    }

    /**
     * Check if this e-mail is valid.
     *
     * @param string $email E-mail address to validate.
     *
     * @return bool
     */
    public static function isValidEmail(string $email): bool
    {
        return Mail::validateAddress($email, 'php');
    }

    /**
     * Look for e-mail in form fields.
     *
     * @param array $data Form data.
     * @param Registry $params Module params.
     *
     * @return string
     */
    protected static function getClientEmail(array $data, Registry $params): string
    {
        $fields = static::getFields($params);

        $email = '';
        foreach ($fields as $name => $field) {

            // Look for first e-mail type field in fields list
            if ($field->type == 'email') {

                if (key_exists($field->title, $data) and !empty($data[$field->title]) and static::isValidEmail($data[$field->title])) {
                    $email = $data[$field->title];
                    break;
                }
            }
        }

        return $email;
    }

    /**
     * Send email form.
     *
     * @param string $body E-mail body.
     * @param string $subject E-mail subject.
     * @param array $recipient Array of E-mail addresses.
     * @param string $reply_to Reply-to e-mail address
     *
     * @return bool
     */
    protected static function sendEmail(string $body, string $subject, array $recipients, string $reply_to = ''): bool
    {

        // E-mail class instance
        $mail = Factory::getMailer();

        // Add recipients
        foreach ($recipients as $recipient) {
            $mail->addRecipient($recipient);
        }

        // Add reply to if exists
        if (!empty($reply_to)) {
            $mail->addReplyTo($reply_to);
        }

        // Set body
        $mail->setBody($body);
        $mail->isHtml();

        // Set subject
        $mail->setSubject($subject);

        // Send the email
        return $mail->Send();
    }

    /**
     * Prepare message body
     *
     * @param string $intro Message intro.
     * @param string $table Message data table.
     *
     * @return string
     */
    protected static function prepareBody(string $intro, string $table): string
    {
        return $intro . $table;
    }

    /**
     * Return captcha code
     *
     * @param Registry $params Module params.
     * @param stdClass $module Module instance.
     *
     * @return string
     *
     * @throws Exception
     */
    public static function getCaptcha(Registry $params, stdClass $module): string
    {

        // Application instance
        $app = Factory::getApplication();

        // Get captcha plugin
        $plugin = static::isCaptchaEnabled($params);
        if ($plugin === false) {
            return '';
        }

        // Prepare namespace
        $namespace = "mod_bpform.{$module->id}.captcha";

        // Try to create captcha field
        try {
            // Get an instance of the captcha class that we are using
            $captcha = Captcha::getInstance($plugin, ['namespace' => $namespace]);

            return $captcha->display('captcha', 'mod_bpform_captcha_' . $module->id);
        } catch (\RuntimeException $e) {
            $app->enqueueMessage($e->getMessage(), 'error');

            return '';
        }
    }

    /**
     * Prepare XML field options for checkboxes,radios and list type fields.
     *
     * @param object $field Field object.
     * @param array $value Field value.
     *
     * @return string
     */
    public static function prepareFieldXMLOptions(object $field, array $value = []): string
    {
        $xml = '';

        // For list/select type fields, add a placeholder if exists
        if (!empty($field->hint) and $field->type === 'list') {
            $xml .= '<option value="">- ' . $field->hint . ' -</option>';
        }

        // Render field options
        $options = (array)$field->options;
        $selected_attribute = $field->type==='list' ? 'selected':'checked';
        foreach ($options as $option) {
            $selected = in_array($option->value, $value) ? ' '.$selected_attribute.'="'.$selected_attribute.'"':'';
            $xml .= '<option value="' . htmlspecialchars($option->value) . '"'.$selected.'>' . htmlspecialchars($option->title) . '</option>';
        }

        return $xml;
    }

    /**
     * Get field value using field value set by user.
     *
     * @param object $field Field object.
     * @param array $default   Default field value (set by user)
     *
     * @return array
     */
    public static function getOptionsFieldValue(object $field, $default = []): array
    {
        $value = (array)$default;
        $value = array_filter($value);
        if (empty($value)) {
            $options = (array)$field->options;
            foreach ($options as $option) {
                if( (bool)$option->selected ) {
                    $value[] = $option->value;
                }
            }
        }

        return $value;
    }

}