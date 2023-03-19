<?php
/**
 * @package org.openpsa.relatedto
 * @author Nemein Oy, http://www.nemein.com/
 * @copyright Nemein Oy, http://www.nemein.com/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 */

/**
 * Class for handling "related to" information
 *
 * @package org.openpsa.relatedto
 */
class org_openpsa_relatedto_plugin extends midcom_baseclasses_components_plugin
{
    /**
     * Shorthand for creating a relatedto object.
     *
     * The <i>from</i> object is something that is related to the <em>to</em>
     * object.
     * For example, if a task is created under a sales project, that task is
     * the from object, and the sales project the to object.
     *
     * @param array $extra Array with the possible extra-properties
     */
    public static function create(object $from_obj, string $from_component, object $to_obj, string $to_component, int $status = null, array $extra = []) : ?org_openpsa_relatedto_dba
    {
        $status ??= org_openpsa_relatedto_dba::CONFIRMED;

        $rel = new org_openpsa_relatedto_dba();
        $rel->fromClass = get_class($from_obj);
        $rel->toClass = get_class($to_obj);
        $rel->fromGuid = $from_obj->guid;
        $rel->toGuid = $to_obj->guid;
        $rel->fromComponent = $from_component;
        $rel->toComponent = $to_component;
        $rel->status = $status;

        if ($guid = $rel->check_db(false)) {
            $db_rel = new org_openpsa_relatedto_dba($guid);
            debug_add("A relation from {$rel->fromClass} #{$rel->fromGuid} to {$rel->toClass} #{$rel->toGuid} already exists, returning this one instead");
            if ($db_rel->status < $rel->status) {
                $db_rel->status = $rel->status;
                $db_rel->update();
            }
            return $db_rel;
        }

        foreach ($extra as $extra_key => $extra_value) {
            $rel->$extra_key = $extra_value;
        }

        if (!$rel->create()) {
            debug_add("failed to create link from {$rel->fromClass} #{$rel->fromGuid} to {$rel->toClass} #{$rel->toGuid}, errstr: " . midcom_connection::get_error_string(), MIDCOM_LOG_WARN);
            return null;
        }

        return $rel;
    }

    /**
     * Parses relatedto information from request, returning either
     * existing matching relatedtos or prefilled new ones for creation
     */
    public static function get2relatedto() : array
    {
        $ret = [];
        if (!array_key_exists('org_openpsa_relatedto', $_REQUEST)) {
            return $ret;
        }
        foreach ($_REQUEST['org_openpsa_relatedto'] as $rel_array) {
            $rel = new org_openpsa_relatedto_dba();
            foreach ($rel_array as $k => $v) {
                $rel->$k = $v;
            }
            if ($guid = $rel->check_db()) {
                $rel = new org_openpsa_relatedto_dba($guid);
            }
            $ret[] = $rel;
        }
        return $ret;
    }

    /**
     * Serializes an array or relatedto objects into GET parameters
     *
     * NOTE: does not prefix the ? for the first parameter in case this needs
     * to be used with some other GET parameters.
     */
    public static function relatedto2get(array $array) : ?string
    {
        $ret = ['org_openpsa_relatedto' => []];
        foreach ($array as $rel) {
            if (!midcom::get()->dbfactory->is_a($rel, org_openpsa_relatedto_dba::class)) { //Matches also 'org_openpsa_relatedto'
                //Wrong type of object found in array, cruelly abort the whole procedure
                return null;
            }
            $entry = [
                'toGuid' => $rel->toGuid,
                'toComponent' => $rel->toComponent,
                'toClass' => $rel->toClass
            ];

            //To save GET space we only append these if they have values
            foreach (['status', 'fromComponent', 'fromClass', 'fromGuid'] as $key) {
                if ($rel->$key) {
                    $entry[$key] = $rel->$key;
                }
            }

            $ret['org_openpsa_relatedto'][] = $entry;
        }
        return http_build_query($ret, '', '&');
    }

    public static function add_button(midcom_helper_toolbar $toolbar, string $guid)
    {
        $toolbar->add_item([
            MIDCOM_TOOLBAR_URL => "__mfa/org.openpsa.relatedto/render/{$guid}/both/",
            MIDCOM_TOOLBAR_LABEL => midcom::get()->i18n->get_string('view related information', 'org.openpsa.relatedto'),
            MIDCOM_TOOLBAR_GLYPHICON => 'paperclip',
        ]);
    }

    private static function common_node_toolbar_buttons_sanitycheck(array &$data, string $button_component, $bind_object, string $calling_component) : ?org_openpsa_relatedto_dba
    {
        if (!midcom::get()->componentloader->is_installed($button_component)) {
            debug_add("component {$button_component} is not installed", MIDCOM_LOG_ERROR);
            return null;
        }
        if (empty($data['node'])) {
            debug_add("data['node'] not given, trying with siteconfig");
            $siteconfig = org_openpsa_core_siteconfig::get_instance();
            $node_guid = $siteconfig->get_node_guid($button_component);
            if (!$node_guid) {
                debug_add("data['node'] not given, and {$button_component} could not be found in siteconfig", MIDCOM_LOG_INFO);
                return null;
            }

            $nap = new midcom_helper_nav();
            $data['node'] = $nap->resolve_guid($node_guid);
            if (empty($data['node'])) {
                //Invalid node given/found
                debug_add("data['node'] is invalid", MIDCOM_LOG_ERROR);
                return null;
            }
        }

        $related_to = new org_openpsa_relatedto_dba();
        $related_to->toGuid = $bind_object->guid;
        $related_to->toClass = get_class($bind_object);
        $related_to->toComponent = $calling_component;
        $related_to->fromComponent = $button_component;
        $related_to->status = org_openpsa_relatedto_dba::CONFIRMED;

        return $related_to;
    }

    public static function common_toolbar_buttons_defaults() : array
    {
        return [
            'event' => [
                'node' => false,
                'component' => 'org.openpsa.calendar'
            ],
            'task'  => [
                'node' => false,
                'component' => 'org.openpsa.projects'
            ],
            'wikinote' => [
                'node' => false,
                'component' => 'net.nemein.wiki',
                'wikiword'  => false, //Calling component MUST define this key to get a wikinote button
            ],
            'document' => [
                'node' => false,
                'component' => 'org.openpsa.documents'
            ],
        ];
    }

    public static function common_node_toolbar_buttons(midcom_helper_toolbar $toolbar, $bind_object, string $calling_component, array $buttons)
    {
        $workflow = new midcom\workflow\datamanager;
        $toolbar_buttons = [];
        foreach ($buttons as $mode => $data) {
            debug_print_r("processing button '{$mode}' with data:", $data);
            if ($data === false) {
                //In case somebody didn't unset() a button from the defaults, just marked it as false
                debug_add('data marked as false, skipping (the correct way is to unset() the key)', MIDCOM_LOG_WARN);
                continue;
            }

            $related_to = self::common_node_toolbar_buttons_sanitycheck($data, $data['component'], $bind_object, $calling_component);
            if (!$related_to) {
                debug_add("sanitycheck returned false, skipping", MIDCOM_LOG_WARN);
                continue;
            }
            //Remember that switch is also a for statement in PHPs mind, use "continue 2"
            switch ($mode) {
                case 'event':
                    $toolbar_buttons[] = org_openpsa_calendar_interface::get_create_button($data['node'], '?' . self::relatedto2get([$related_to]));
                    break;
                case 'task':
                    if (midcom::get()->auth->can_user_do('midgard:create', null, org_openpsa_projects_task_dba::class)) {
                        $toolbar_buttons[] = $workflow->get_button("{$data['node'][MIDCOM_NAV_ABSOLUTEURL]}task/new/?" . self::relatedto2get([$related_to]), [
                            MIDCOM_TOOLBAR_LABEL => midcom::get()->i18n->get_string('create task', $data['component']),
                            MIDCOM_TOOLBAR_GLYPHICON => 'calendar-check-o',
                            MIDCOM_TOOLBAR_OPTIONS => ['data-refresh-opener' => 'true'],
                        ]);
                    }
                    break;
                case 'wikinote':
                    if (empty($data['wikiword'])) {
                        //Wikiword to use not given
                        debug_add("data['wikiword'] not given, skipping", MIDCOM_LOG_WARN);
                        continue 2;
                    }

                    if (!net_nemein_wiki_interface::node_wikiword_is_free($data['node'], $data['wikiword'])) {
                        //Wikiword is already reserved
                        //PONDER: append number or something and check again ??
                        debug_add("node_wikiword_is_free returned false for '{$data['wikiword']}'", MIDCOM_LOG_WARN);
                        continue 2;
                    }

                    $data['wikiword_encoded'] = rawurlencode($data['wikiword']);
                    $toolbar_buttons[] = $workflow->get_button("{$data['node'][MIDCOM_NAV_ABSOLUTEURL]}create/?wikiword={$data['wikiword_encoded']}&" . self::relatedto2get([$related_to]), [
                        MIDCOM_TOOLBAR_LABEL => midcom::get()->i18n->get_string('create note', $data['component']),
                        MIDCOM_TOOLBAR_GLYPHICON => 'sticky-note-o',
                        MIDCOM_TOOLBAR_ENABLED => $data['node'][MIDCOM_NAV_OBJECT]->can_do('midgard:create'),
                        MIDCOM_TOOLBAR_OPTIONS => ['data-refresh-opener' => 'true'],
                    ]);
                    break;
                case 'document':
                    if ($data['node'][MIDCOM_NAV_OBJECT]->can_do('midgard:create')) {
                        $toolbar_buttons[] = $workflow->get_button("{$data['node'][MIDCOM_NAV_ABSOLUTEURL]}document/create/?" . self::relatedto2get([$related_to]), [
                            MIDCOM_TOOLBAR_LABEL => midcom::get()->i18n->get_string('create document', $data['component']),
                            MIDCOM_TOOLBAR_GLYPHICON => 'file-o',
                            MIDCOM_TOOLBAR_OPTIONS => ['data-refresh-opener' => 'true'],
                        ]);
                    }
                    break;
                default:
                    debug_add("given button '{$mode}' not recognized", MIDCOM_LOG_ERROR);
                    break;
            }
        }
        $toolbar->add_items($toolbar_buttons);
    }

    /**
     * function to add the button for journal_entry to the toolbar
     */
    public static function add_journal_entry_button(midcom_helper_toolbar $toolbar, string $guid)
    {
        $toolbar->add_item([
            MIDCOM_TOOLBAR_URL => "__mfa/org.openpsa.relatedto/journalentry/{$guid}/",
            MIDCOM_TOOLBAR_LABEL => midcom::get()->i18n->get_string('view journal entries', 'org.openpsa.relatedto'),
            MIDCOM_TOOLBAR_GLYPHICON => 'list',
        ]);
    }
}
