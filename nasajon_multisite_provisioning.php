<?php
/**
Script responsavel por provisionar authsources automaticamente no SP do simplesamlphp

Exemplo:
php /var/www/nasajon_multisite_provisioning.php --entity_id=c20 --nome="Silvio Santos" --email=silvio20@as.c --senha=senha
*/

date_default_timezone_set('America/Sao_Paulo');

ignore_user_abort(true);

$aspath = '/var/www/vhosts/saml/config/authsources.php';

$rmsrvc = array(
	    'host'=>'diretorio.nasajonsistemas.com.br',
	    'service_provider'=>'/api/ServiceProvider/',
            'service_conta'=>'/api/conta/',
	    'priv_key'=>'282a8a152b801a61e6d71d26276a978c',
	    'id_sistm'=>'242',
 	  );

    function s($str)
    {
      if($str){
        echo $str . "\n";
        flush();
      }
    }

    function report_error($message)
    {
      die($message);
      throw new Exception($message);
    }

    function do_post_request($url, $data, $auth_token)
    {
        $headers = array('auth_token: '.$auth_token);

        $ch = curl_init();

        curl_setopt($ch,CURLOPT_URL, $url);
        curl_setopt($ch,CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch,CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER, TRUE);

        $result = curl_exec($ch);

        if (curl_error($ch)) 
        {
           return array('sucesso'=>FALSE, 'erros'=>curl_error($ch));
        }
        else
        {
           return (array)json_decode($result);
        }

        curl_close($ch);
    }

    function provisioning_local_sp_authsource($entity_id, $aspath)
    {
        if(! is_file($aspath))
        {
            report_error("O arnuivo authsources.php não foi encontrado.\n");
        }

        if (preg_match("/[a-zA-Z0-9_]/i", $entity_id))
        {
            require($aspath);

            $eid = 'web.nasajon.com.br.' . $entity_id;

            if( ! array_key_exists($eid,$config))
            {
                $config[$eid] = array(
                    'saml:SP',
                    'entityID' => $eid,
                    'idp' => 'login.nasajonsistemas.com.br',
                    'discoURL' => NULL,
                );

                $arrcontent = var_export($config,TRUE);

                if( file_put_contents ( $aspath,"<?php\n\n\$config = {$arrcontent};" ))
                {
                    echo "EntityID adocionado ao SP local com sucesso\n";
                    return TRUE;
                }
                else
                {
                    echo "Erro ao tentar adicionar o EntityID no SP local\n";
                     return FALSE;
                }
            }
            else
            {
                echo "\nO entity_id {$eid} já existe\n";

            }
        }
        else
        {
            echo "Caracter inválido no entity_id (informe apenas letras, números e underscore)\n";
        }

        return FALSE;
    }

    function provisioning_remote_idp_authsource($entity_id,$rmsrvc)
    {
        try
        {
            $auth_token = md5( date('c') . $rmsrvc['priv_key'] ) .'@'. base64_encode( date('c') .','. $rmsrvc['id_sistm'] );
            $uri_servic = 'http://'.$rmsrvc['host'].$rmsrvc['service_provider'];

            $data = array(
             'entityid'=>'web.nasajon.com.br.'.$entity_id,
             'assertionconsumerservice'=>"http://web.nasajon.com.br/{$entity_id}/simplesaml/module.php/saml/sp/saml2-acs.php/web.nasajon.com.br.{$entity_id}",
             'singlelogoutservice'=>"http://web.nasajon.com.br/{$entity_id}/simplesaml/module.php/saml/sp/saml2-logout.php/web.nasajon.com.br.{$entity_id}",
            );

            $rmot_rsult = do_post_request($uri_servic, $data, $auth_token);


            if(is_array($rmot_rsult))
            {
                if(isset($rmot_rsult['sucesso']) && $rmot_rsult['sucesso']==FALSE)
                {
                    echo 'Erro ao tentar criar metadado de SP remoto: '.
                          (isset($rmot_rsult['mensagem']) ? $rmot_rsult['mensagem'] : '') . "\n";
                    return FALSE;
                }
                else
                {   
                    echo "EntityID adicionado ao IDP remoto com sucesso\n";
                    #var_export($rmot_rsult);
                    return TRUE;
                }
            }
            else
            {
               echo 'Erro desconhecido';
            }
        }
        catch(Exception $e)
        {
        }
        return FALSE;
    }

    function provisioning_remote_idp_user($received, $rmsrvc)
    {
        try
        {
            $auth_token = md5( date('c') . $rmsrvc['priv_key'] ) .'@'. base64_encode( date('c') .','. $rmsrvc['id_sistm'] );
            $uri_servic = 'http://'.$rmsrvc['host'].$rmsrvc['service_conta'];

            $rmot_rsult = do_post_request($uri_servic, $received, $auth_token);


            if(is_array($rmot_rsult))
            {
                if(isset($rmot_rsult['sucesso']) && $rmot_rsult['sucesso']==FALSE)
                {
                   throw new Exception('Erro ao tentar criar usuário: '.(isset($rmot_rsult['mensagem']) ? $rmot_rsult['mensagem'] : '') . "\n");
                }
                else
                {
                  echo "Usuário criado com sucesso com sucesso no IDP\n";

                  $e = $received['entity_id'];

                  exec("cd /var/www/vhosts/multisite/sites/web.nasajon.com.br.{$e}; ".
                       "drush user-create \"{$received['email']}\" --mail=\"{$received['email']}\"".
                       " --password=\"{$received['senha']}\" --format=json", $jsonusr);

                  $nusrids = array_keys((array)json_decode(implode('',$jsonusr)));

                  echo "Usuário local criado com sucesso\n";

                  exec("cd /var/www/vhosts/multisite/sites/web.nasajon.com.br.{$e}; ".
                       "drush user-add-role \"administrator\" --mail={$received['email']}");

                  echo "Usuário local definido como administrador\n";

                  exec("cd /var/www/vhosts/multisite/sites/web.nasajon.com.br.{$e}; ".
                       "drush sql-query \"INSERT INTO authmap (uid,authname,module) values ({$nusrids[0]},'{$received['email']}','simplesamlphp_auth')\"");

                  echo "Usuário local associado com sucesso ao usuário remoto\n";
 
                  return TRUE;
                }
            }
            else
            {
               throw new Exception('Formato de respota inválido após solicitação de cadstro de usuário');
            }
        }
        catch(Exception $e)
        {
            report_error("Erro:".$e->getmessage()."\n");
        }
    }

    function tarefas_drush($e,$n,$m,$p,$siten=NULL)
    {
        $siten = is_null($siten) || empty($siten) ? $n : $siten;

        #Temporariamente o usuario e email basicos serão um usuário da nasajon
        $p = 'admin';
        $m = 'drivecontabil@nasajon.com.br';

        #Drush mode... 
        s(exec("cd /var/www/vhosts/multisite; ".
             "drush -y site-install standard install_configure_form.site_default_country=BR --site-name=\"{$siten}\" ".
             "--db-url=pgsql://postgres:postgres@multisite.cvpk2aht8ryw.us-west-2.rds.amazonaws.com:5432/{$e}  ".
             "--account-name={$m} --account-pass={$p}  --account-mail={$m} --locale=pt-BR  ".
             "--sites-subdir=web.nasajon.com.br.{$e}  --clean-url=0"));

        s(exec("cd /var/www/vhosts/multisite; ln -s . {$e}"));

        s(exec("cd /var/www/vhosts/multisite/sites/web.nasajon.com.br.{$e}; drush -y en simplesamlphp_auth"));
        s(exec("cd /var/www/vhosts/multisite/sites/web.nasajon.com.br.{$e}; drush pm-list --no-core --type=module --status=enabled –pipe"));
        s(exec("cd /var/www/vhosts/multisite/sites/web.nasajon.com.br.{$e}; drush eval 'variable_set(\"simplesamlphp_auth_activate\", 1)'"));
        s(exec("cd /var/www/vhosts/multisite/sites/web.nasajon.com.br.{$e}; drush eval 'variable_set(\"simplesamlphp_auth_allowdefaultlogin\", 0)'"));
        s(exec("cd /var/www/vhosts/multisite/sites/web.nasajon.com.br.{$e}; ".
             'drush eval \'variable_set("simplesamlphp_auth_allowdefaultloginroles", array(2=>"2"))\''));
        s(exec("cd /var/www/vhosts/multisite/sites/web.nasajon.com.br.{$e}; drush eval 'variable_set(\"simplesamlphp_auth_allowdefaultloginusers\",\"\")'"));
        s(exec("cd /var/www/vhosts/multisite/sites/web.nasajon.com.br.{$e}; drush eval 'variable_set(\"simplesamlphp_auth_allowsetdrupalpwd\", 0)'"));
        s(exec("cd /var/www/vhosts/multisite/sites/web.nasajon.com.br.{$e}; ".
             "drush eval \"variable_set(\"simplesamlphp_auth_authsource\", 'web.nasajon.com.br.{$e}')\""));
        s(exec("cd /var/www/vhosts/multisite/sites/web.nasajon.com.br.{$e}; drush eval 'variable_set(\"simplesamlphp_auth_forcehttps\", 0)'"));
        s(exec("cd /var/www/vhosts/multisite/sites/web.nasajon.com.br.{$e}; ".
             'drush eval \'variable_set("simplesamlphp_auth_installdir", "/var/www/vhosts/saml")\''));
        s(exec("cd /var/www/vhosts/multisite/sites/web.nasajon.com.br.{$e}; drush eval 'variable_set(\"simplesamlphp_auth_logoutgotourl\",\"\")'"));
        s(exec("cd /var/www/vhosts/multisite/sites/web.nasajon.com.br.{$e}; drush eval 'variable_set(\"simplesamlphp_auth_mailattr\", \"email\")'"));
        s(exec("cd /var/www/vhosts/multisite/sites/web.nasajon.com.br.{$e}; drush eval 'variable_set(\"simplesamlphp_auth_registerusers\", 0)'"));
        s(exec("cd /var/www/vhosts/multisite/sites/web.nasajon.com.br.{$e}; drush eval 'variable_set(\"simplesamlphp_auth_roleevaleverytime\", 0)'"));
        s(exec("cd /var/www/vhosts/multisite/sites/web.nasajon.com.br.{$e}; ".
             'drush eval \'variable_set("simplesamlphp_auth_rolepopulation", "1:eduPersonPrincipalName,@=,nome;affiliation,=,employee|2:mail,=,email")\''));
        s(exec("cd /var/www/vhosts/multisite/sites/web.nasajon.com.br.{$e}; drush eval 'variable_set(\"simplesamlphp_auth_unique_id\", \"email\")'"));
        s(exec("cd /var/www/vhosts/multisite/sites/web.nasajon.com.br.{$e}; drush eval 'variable_set(\"simplesamlphp_auth_user_name\", \"nome\")'"));
        s(exec("cd /var/www/vhosts/multisite/sites/web.nasajon.com.br.{$e}; drush eval 'variable_set(\"simplesamlphp_auth_user_register_original\", 2)'"));
        return TRUE;
    }

    $expecting = array('entity_id','nome','email','senha');
    $received = array();
 
    $help = "\n".
            "Nasajon Provisionador de entidades para sites drupal 1b, provisionando por linha de comando objetos da api nasajon.\n".
            "Uso: provisao_nasajon [OPTION]...\n\n".
            "Todos os argumentos dispostos a seguir são obrigatórios e existem apenas no modo extendido.\n\n".
            "Detalhes:\n".
            "  --entity_id           A entity_id a ser provisionada no SP local e no IDP central remoto.\n".
            "  --nome                Nome do usuário principal.\n".
            "  --email               Email do usuário principal.\n".
            "  --senha               A senha deste usuário para login no IDP.\n";

    if(count($argv) == count($expecting)+1)
    {
        foreach($argv as $p)
        {
           if(strrpos($p,'='))
           {
               list($a,$b) = explode('=',$p);
               if(in_array(substr($a, 2),$expecting))
               {
                 $received[substr($a, 2)] = $b;
               }
               if(is_null($b) || empty($b))
               {
                   report_error("Valor inválido para {$a}.\n");
               }
           }
        }

        if(count(array_diff($expecting, array_keys($received))) ==0 )
        {
            if($r1 = tarefas_drush($received['entity_id'],$received['nome'],$received['email'],$received['senha']))
            {
                if($r1 = provisioning_local_sp_authsource($received['entity_id'], $aspath))
                {
                    if($r1 = provisioning_remote_idp_authsource($received['entity_id'], $rmsrvc))
                    {
                        if($r1 = provisioning_remote_idp_user($received, $rmsrvc))
                        {
                            echo "Sucesso na criação do site {$received['entity_id']}\n";
                            echo "Agora só falta implementar o envio do email e confirmação\n";
                            return TRUE;
                        }
                    }
                }
            }

            report_error("Erro desconhecido.\n");
        }
        else
        {
            report_error("Parametros inválidos.\n");           
        }
    }
    else
    {
        echo "Você deve informar obrigatoriamente ".count($expecting)." parâmetros. \n" . $help;
    }

