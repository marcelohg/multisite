<?php 
/**

Notas
	1 - Quando tenta-se cadastrar um usuário e o sistema insiste em colocar como o nome dele o nome default do proprio sistema
	é problema no simplesaml_auth > a forma rápida de corrigir é comentar a linha 367 do arquivo: /var/www/vhosts/multisite/sites/all/modules/simplesamlphp_auth/simplesamlphp_auth.module

*/

class NasajonUtils
{
    public static $aspath = '/var/www/vhosts/saml/config/authsources.php';

    public static $rmsrvc = array(
            'host'=>'diretorio.nasajonsistemas.com.br',
            'service_provider'=>'/api/ServiceProvider/',
            'service_conta'=>'/api/conta/',
            'priv_key'=>'282a8a152b801a61e6d71d26276a978c',
            'id_sistm'=>'242',
          );

    public static function do_post_request($url, $data, $auth_token, $method='POST')
    {
        $headers = array('auth_token: '.$auth_token);

        $ch = curl_init();

        curl_setopt($ch,CURLOPT_URL, $url);
        curl_setopt($ch,CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch,CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER, TRUE);

        if($method=='PUT')
        {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($ch, CURLOPT_POSTFIELDS,http_build_query($data));
        }

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

    public static function idp_create_account($entity_id, $roles, $uid, $status, $name, $mail, $pass)
    {
        try
        {
            $auth_token = md5( date('c') . NasajonUtils::$rmsrvc['priv_key'] ) .'@'. base64_encode( date('c') .','. NasajonUtils::$rmsrvc['id_sistm'] );
            $uri_servic = 'http://'.NasajonUtils::$rmsrvc['host'].NasajonUtils::$rmsrvc['service_conta'];

            $funcoes = ! is_null($roles) ? (string)$roles : '';

            $rmot_rsult = NasajonUtils::do_post_request($uri_servic, 
				array('status'=>$status,'nome'=>$name,'email'=>$mail,'senha'=>$pass, 'funcoes'=>$funcoes), $auth_token);

            if(is_array($rmot_rsult))
            {
                if(isset($rmot_rsult['sucesso']) && $rmot_rsult['sucesso']==FALSE)
                {
                   throw new Exception('Erro ao tentar criar usuário');
                }
                else
                {
                  return $rmot_rsult;
                }
            }
            else
            {
               throw new Exception('Formato de respota inválido após solicitação de cadstro de usuário');
            }
        }
        catch(Exception $e)
        {
            error_log( $e->getmessage().' - '.$uri_servic.' - '.var_export($rmot_rsult,TRUE) );
        }

	return FALSE;
    }

    public static function idp_update_account($entity_id, $roles, $uid, $status, $name, $mail, $pass)
    {
        try
        {
            $auth_token = md5( date('c') . NasajonUtils::$rmsrvc['priv_key'] ) .'@'. base64_encode( date('c') .','. NasajonUtils::$rmsrvc['id_sistm'] );

            $uri_servic = 'http://'.NasajonUtils::$rmsrvc['host'].NasajonUtils::$rmsrvc['service_conta'].$mail;

            $funcoes = ! is_null($roles) ? (string)$roles : '';

            $rmot_rsult = NasajonUtils::do_post_request($uri_servic, 
                                array('status'=>$status,'nome'=>$name,'email'=>$mail,'senha'=>$pass, 'funcoes'=>$funcoes), $auth_token, 'PUT');

            if(is_array($rmot_rsult))
            {
                if(isset($rmot_rsult['sucesso']) && $rmot_rsult['sucesso']==FALSE)
                {
                   throw new Exception('Erro ao tentar atualizar usuario');
                }
                else
                {
                  return $rmot_rsult;
                }
            }
            else
            {
               throw new Exception('Formato de respota inválido após solicitação de cadstro de usuário');
            }
        }
        catch(Exception $e)
        {
            error_log( $e->getmessage().' - ['.$uri_servic.'] - '.var_export($rmot_rsult,TRUE) );
        }

        return FALSE;
    }
                
    public static function drupal_auth_idp_user($uid, $mail, $path_subsite)
    {
        # $path_subsite =  /var/www/vhosts/multisite/sites/web.nasajon.com.br.c1
        try
        {
            if(is_dir($path_subsite))
            {
                exec("cd {$path_subsite} drush sql-query \"INSERT INTO authmap (uid,authname,module) values ({$uid},'{$mail}','simplesamlphp_auth')\"");
                return TRUE;
            }
        }
        catch(Exception $e)
        {
            error_log( $e->getmessage().' - '.var_export($rmot_rsult,TRUE) );
        }

        return FALSE;
    }             
}

