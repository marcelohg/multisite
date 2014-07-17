<?php
    #NASAJON RULES
    #Como o presave nao tava sendo chamada colocamos aqui o gerenciamento de usuário remoto
    #este codigo deve ser incluido na linha 608 do arquivo /var/www/vhosts/multisite/modules/user/user.module 

    if(isset($_POST['form_id']) && in_array($_POST['form_id'],array('user_profile_form','user_register_form')))
    {
        require_once("nasajon_utils.php");

        $entity_id = variable_get('simplesamlphp_auth_authsource', NULL);
        $roles     = implode('|',array_values(($account->is_new)?$account->roles:$account->original->roles));

        $passw     = isset($_POST['pass']) && $_POST['pass']['pass1']==$_POST['pass']['pass2'] ? $_POST['pass']['pass1'] : NULL;

        if(!$account->is_new)
        {
            $idp_account = NasajonUtils::idp_update_account(
                                        $entity_id, $roles, $account->uid, $account->status, $account->name, $account->mail, $passw);
        }
        else
        {
            $idp_account = NasajonUtils::idp_create_account(
                                        $entity_id, $roles, $account->uid, $account->status, $account->name, $account->mail, $passw);
        }

        $saml_auth = NasajonUtils::drupal_auth_idp_user($account->uid, $account->mail, $entity_id);

        if($account->is_new)
        {
            authorize_sal_login($account->uid, $account->mail);
        }
    }

    function authorize_sal_login($uid, $email, $module='simplesamlphp_auth')
    {
        $txn = db_transaction();

        try 
        {
          $id = db_insert('authmap')
            ->fields(array(
              'uid' => $uid,
              'authname' => $email,
              'module'=>$module
            ))
            ->execute();

          return $id;
        }
        catch (Exception $e)
        {
          $txn->rollback();
          watchdog_exception('type', $e);
        }
    } 
