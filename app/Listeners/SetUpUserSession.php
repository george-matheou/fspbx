<?php

namespace App\Listeners;

use App\Models\Domain;
use App\Models\UserGroup;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class SetUpUserSession
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  object  $event
     * @return void
     */
    public function handle(Login $event)
    {
        //Push variable to Session
        Session::put('$user_uuid', $event->user->user_uuid);
        Session::put('user.user_uuid', $event->user->user_uuid);
        Session::put('user.domain_uuid', $event->user->domain_uuid);
        $domain = Domain::where('domain_uuid',$event->user->domain_uuid)->first();
        Session::put('user.domain_name', $domain->domain_name);
        
        //get the groups assigned to the user and then set the groups in $_SESSION["groups"]
        $groups = DB::table('v_user_groups')
            ->join('v_groups', 'v_user_groups.group_uuid', '=', 'v_groups.group_uuid')
                ->where([
                    ['v_user_groups.user_uuid', '=', $event->user->user_uuid],
                    //['v_user_groups.user_uuid', '=', "71d03aac-02e2-444f-90de-14a439892b0c"], //reseller
                    ['v_user_groups.domain_uuid', '=', $event->user->domain_uuid],
                ])
                    ->get(['v_user_groups.group_uuid','v_user_groups.group_name','group_level']);
        
        Session::put('user.groups', $groups);

       
        //get the users highest level group
        Session::put('user.group_level', 0);
		foreach ($groups as $group) {
		    if (Session::get('user.group_level') < $group->group_level) {
			    Session::put('user.group_level', $group->group_level);
			}
            $group_names[] = $group->group_name;
            $group_uuids[] = $group->group_uuid;
		}

        // set menu id for the user
        $menu_uuid = DB::table('v_user_settings')
            -> where ([
                ['user_uuid', '=', $event->user->user_uuid],
                ['user_setting_subcategory', '=', 'menu'],
            ])
                -> value('user_setting_value');
        // user_setting_value": "9c143165-fda6-4539-a607-4184eb05b065"
        
        // If user doesn't have a custom menu assign the default one
        if (is_null($menu_uuid)) {
            $menu_uuid = DB::table('v_menus')
            -> where ([
                ['menu_name', '=', 'default'],
            ])
                -> value('menu_uuid');
        }
        if (!is_null($menu_uuid)) {
            Session::put('user.menu_uuid', $menu_uuid);
        }

        // Build top level menu
        $main_menu = DB::table('v_menu_items')
        -> join ('v_menu_item_groups', 'v_menu_item_groups.menu_item_uuid', '=', 'v_menu_items.menu_item_uuid')
        -> where ('v_menu_items.menu_uuid', '=', $menu_uuid)
            -> whereNull('v_menu_items.menu_item_parent_uuid')
                -> whereIn('v_menu_item_groups.group_uuid', $group_uuids)
                -> distinct()
                -> orderBy("menu_item_order")
                -> get([
                    'v_menu_items.menu_item_uuid',
                    'v_menu_items.menu_item_title',
                    'v_menu_items.menu_item_link',
                    'menu_item_icon',
                    'menu_item_order',
                ]);
        

        //Build child menus
        foreach ($main_menu as $menu){
            $child_menu = DB::table('v_menu_items')
                -> join ('v_menu_item_groups', 'v_menu_item_groups.menu_item_uuid', '=', 'v_menu_items.menu_item_uuid')
                    -> where ([
                        ['menu_item_parent_uuid', '=', $menu->menu_item_uuid],
                        //['menu_item_parent_uuid', '=', ''],
                    ])
                    -> whereIn('v_menu_item_groups.group_uuid', $group_uuids)
                    -> distinct()
                    -> orderBy("menu_item_order")
                    ->get([
                        'v_menu_items.menu_item_uuid',
                        'v_menu_items.menu_item_title',
                        'v_menu_items.menu_item_link',
                        'menu_item_icon',
                        'menu_item_order',
                    ]);
            $menu->child_menu = $child_menu;

        }
        
        // Add menu to session variable
        Session::put('menu', $main_menu);

        // Build permissions.
        //get the permissions assigned to the groups that the user is a member of set the permissions in $_SESSION['permissions']

        $permissions = DB::table('v_permissions')
        -> join ('v_group_permissions', 'v_permissions.permission_name', '=', 'v_group_permissions.permission_name')
        -> whereIn('v_group_permissions.group_uuid', $group_uuids)
        -> where (function ($permissions) use ($domain) {
            $permissions->where('v_group_permissions.domain_uuid', '=', $domain->domain_uuid)
                -> orWhereNull('v_group_permissions.domain_uuid');
            })
                -> distinct()
                -> get([
                    'v_permissions.permission_uuid',
                    'v_permissions.permission_name',
                ]); 

        // dd($permissions);

        // Add permissions to session variable
        Session::put('permissions', $permissions);


        // Build domains.
        // get the domains that the user is allowed to see and save in $_SESSION['domains']
        session_start();
        session_unset();

        if (userCheckPermission("domain_select")){
            Session::put('domain_select', true);

            // Get domains
            if (in_array('superadmin',$group_names)){
                $domains = DB::table('v_domains')
                    ->where ('domain_enabled','=', 't')
                    ->orderBy('domain_name','asc')
                    ->orderBy('domain_description', 'asc')
                    ->get([
                        'domain_uuid',
                        'domain_parent_uuid',
                        'domain_name',
                        'domain_enabled',
                        DB::Raw('coalesce(domain_description , domain_name) as domain_description')
                    ]); 
            }
            elseif (in_array('reseller',$group_names)) {
                $domains = DB::table('v_domains')
                    ->join ('user_domain_permission', 'user_domain_permission.domain_uuid', '=', 'v_domains.domain_uuid')
                    ->where ('v_domains.domain_enabled','=', 't')
                    ->where('user_uuid','=',$event->user->user_uuid)
                    ->orderBy('v_domains.domain_name','asc')
                    ->orderBy('v_domains.domain_description', 'asc')
                    ->get([
                        'v_domains.domain_uuid',
                        'v_domains.domain_parent_uuid',
                        'v_domains.domain_name',
                        'v_domains.domain_enabled',
                        DB::Raw('coalesce(v_domains.domain_description , v_domains.domain_name) as domain_description')
                    ]); 
            }

            Session::put('domains', $domains);

            // Pass domains array to FusionPBX
            foreach(json_decode(json_encode($domains), true) as $row) {	
                $_SESSION['domains'][$row['domain_uuid']] = $row;
            }

        }

        // Assign additional variables
        if (in_array('reseller',$group_names)) {
            //if user is in the reseller group check if he is allowed to see his own domain
            // print $domain->domain_uuid;
            // print "<br><pre>";
            // print_r($_SESSION['domains']);
            // print "</pre>";
            $self_domain=false;
            foreach ($_SESSION['domains'] as $key=>$val){
                if ($key == $domain->domain_uuid){
                    $self_domain = true;
                    break;
                }
            }
            if($self_domain) {
                Session::put('domain_uuid', $val['domain_uuid']);
                Session::put('domain_name', $val['domain_name']);
                $_SESSION["domain_name"] = $val['domain_name'];
                $_SESSION["domain_uuid"] = $val['domain_uuid'];

            } else {
                // if not then grab first domain from the list of allowed domains
                $first_domain = reset($_SESSION['domains']);
                Session::put('domain_uuid', $first_domain['domain_uuid']);
                Session::put('domain_name', $first_domain['domain_name']);
                $_SESSION["domain_name"] = $first_domain['domain_name'];
                $_SESSION["domain_uuid"] = $first_domain['domain_uuid'];
            }

        } else {
            Session::put('domain_uuid', $domain->domain_uuid);
            Session::put('domain_name', $domain->domain_name);
            $_SESSION["domain_name"] = $domain->domain_name;
            $_SESSION["domain_uuid"] = $domain->domain_uuid;
        }


        //dd(Session::all());
    }
}
