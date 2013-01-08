<?php
namespace core;

require_once(__DIR__ . '/Constants.php');

/**
 * SGA Base class and request handler
 * 
 * contem alguns metodos do sistema
 */
class SGA {
    
    const NAME = "Novo SGA";
    const FULL_NAME = "Sistema de Gerenciamento de Atendimento";
    const VERSION = "0.1.1";
    const CHARSET = "utf-8";
    
    // SESSION KEYS
    const K_CURRENT_MODULE  = "SGA_CURRENT_MODULE";
    const K_CURRENT_USER    = "SGA_CURRENT_USER";
    const K_LOGIN_ERROR     = "SGA_LOGIN_ERROR";
    
    // URL KEYS
    const K_INSTALL  = "install";
    const K_LOGIN    = "login";
    const K_HOME     = "home";
    const K_PANEL    = "painel";
    const K_MODULE   = "module";
    const K_PAGE     = "page";
    const K_LOGOUT   = "logout";
        
    private static $context;
    private static $doctrineClassLoader;
    
    /**
     * Returns SGAContext
     * @return SGAContext
     */
    public static function getContext() {
        if (self::$context == null) {
            self::$context = new SGAContext();
        }
        return self::$context;
    }
    
    /**
     * Autentica o usuario do SGA
     * @param type $user
     * @param type $pass
     * @return Usuario|null
     */
    public static function auth($login, $pass) {
        $pass = Security::passEncode($pass);
        $em = \core\db\DB::getEntityManager();
        $query = $em->createQuery("SELECT u FROM core\model\Usuario u WHERE u.login = :login");
        $query->setParameter('login', $login);
        try {
            $user = $query->getSingleResult();
            $success = ($user && $user->getSenha() == $pass);
            if ($success) {
                // atualizando o session id
                $user->setSessionId(session_id());
                $user->setAtivo(true);
                $em->merge($user);
                $em->flush();
                return $user;
            }
            return null;
        } catch (\Exception $e) { // no result
            return false;
        }
    }
    
    /**
     * Echo wrapper
     */
    public static function out($str, array $args = array()) {
        if (sizeof($args)) {
            $str = printf($str, $args);
        }
        echo addslashes(str_replace('<', '&lt;', str_replace('>', '&gt;', $str)));
    }
    
    /**
     * Importa um arquivo PHP a partir do nome
     * @param type $param
     * @return type
     */
    public static function import($arg, $return = false) { 
        if (is_array($arg)) {
            $module = \core\util\Arrays::value($arg, SGA::K_MODULE);
            if ($module instanceof Modulo) {
                $modulePath = $module->getPath();
            } else {
                $modulePath = Modulo::path($module);
            }
            $page = \core\util\Arrays::value($arg, SGA::K_PAGE, 'index.php');
            $path = $modulePath . DS . $page;
        } else {
            $a = explode('?', $arg);
            $path = $a[0];
        }
        // removendo ".." e "./" para evitar acessar arquivos fora do diretorio do modulo
        $path = preg_replace('/\.\//', '/', preg_replace('/\.\./', '.', $path));
        $filename = ROOT . DS . $path;
        if (file_exists($filename)) {
            if ($return) {
                ob_start();
            }
            require_once($filename);
            if ($return) {
                $content = ob_get_contents();
                ob_end_clean();
                return $content;
            }
        } else {
            throw new \Exception(sprintf(_('Arquivo não encontrado: %s'), $filename));
        }
        return true;
    }
    
    
    public static function defaultClientLanguage() {
        $langs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
        return current(explode('-', $langs[0]));
    }
    
    public static function redirect($arg) {
        // se passou uma url completa
        if (substr($arg, 0, 7) == 'http://' || substr($arg, 0, 7) == 'https://') {
            $url = $arg;
        } else {
            $url = SGA::url($arg);
        }
        header("Location: " . $url);
        exit();
    }
    
    public static function reload() {
        self::redirect(SGA::url());
    }
    
    public static function checkAccess($key, $value) {
        if (self::isProtectedPage($key)) {
            $context = self::getContext();
            if (!self::isLogged() || !self::isValidSession()) {
                if ($context->getRequest()->isAjax()) {
                    $response = new \core\http\AjaxResponse();
                    $response->success = false;
                    $response->sessionInactive = true;
                    $context->getResponse()->jsonResponse($response);
                } else {
                    SGA::redirect('/' . SGA::K_LOGIN);
                }
            }

            if (self::isHomePage($key)) {
                return true;
            }
            $module = $context->getModule();
            if (!$module) { // invalid or inactive module
                return false;
            }
            $module->getChave();
            if (SGA::hasAccess($module)) {
                return true;
            }
            // TODO: adicionar mensagem de acesso negado
            SGA::redirect('/' . SGA::K_HOME);
        }
    }
    
    public static function isLoginPage($key) {
        return $key == self::K_LOGIN;
    }
    
    public static function isHomePage($key) {
        return $key == self::K_HOME;
    }
    
    public static function isModulePage($key) {
        return $key == self::K_MODULE;
    }
    
    public static function isProtectedPage($key) {
        return self::isHomePage($key) || self::isModulePage($key);
    }
    
    public static function hasAccess(Modulo $modulo) {
        if (SGA::isLogged() && SGA::isValidSession()) {
            $usuario = SGA::getContext()->getUser();
            $unidade = $usuario->getUnidade();
            $id_usu = $usuario->getId();

            if ($modulo->isGlobal()) {
                return \core\db\DB::getAdapter()->hasAccess_global($id_usu, $modulo->getId());
            } else {
                // unidade não informada, mas o módulo NÂO é global
                if ($unidade == null) {
                    throw new \Exception(_('A permissão para módulos não globais depende da unidade.'));
                }

                // módulo unidade
                if ($usuario->getLotacao()) {
                    return $usuario->getLotacao()->get_cargo()->has_permissao($modulo->getId());
                }
            }
        }
        return false;
    }
    
    public static function isLogged() {
        return self::getContext()->getUser() != null;
    }
    
    public static function isValidSession() {
        $user = self::getContext()->getUser();
        if (!$user->isAtivo()) {
            return false;
        }
        // verificando session id
        $em = \core\db\DB::getEntityManager();
        $query = $em->createQuery("SELECT u.sessionId FROM \core\model\Usuario u WHERE u.id = :id");
        $query->setParameter('id', $user->getId());
        $rs = $query->getSingleResult();
        return $user->getSessionId() == $rs['sessionId'];
    }

    public static function hasUnidade() {
        return SGA::isLogged() && SGA::getContext()->getUser()->get_unidade() != null;
    }
    
    public static function url() {
        if (func_num_args() == 0) {
            return $_SERVER['REQUEST_URI'];
        } else {
            $arg = func_get_arg(0);
            if (!is_array($arg)) {
                if ($arg[0] == '/') {
                    return '?' . substr($arg, 1);
                }
                $arg = array(SGA::K_PAGE => $arg);
            }
            $url = '';
            $module = \core\util\Arrays::value($arg, SGA::K_MODULE);
            if (empty($module) && defined('MODULE')) {
                $module = MODULE;
            }
            if (!empty($module)) {
                $url .= SGA::K_MODULE . '=' . $module;
                $page = \core\util\Arrays::value($arg, SGA::K_PAGE);
                if (!empty($page)) {
                    $url .= '&' . SGA::K_PAGE . '=' . $page;
                }
            }
            return (!empty($url)) ? "?$url" : self::url();
        }
    }
    
    /**
     * Auto Load
     * Carrega automaticamente as classes requisitadas, buscando dentro do core do sistema e/ou no modulo.
     * @param String $className
     * @return boolean
     */
    public static function load($className) {
        // finding in the core
        $filename = ROOT . DS . str_replace("\\", DS, $className) . ".php";
        if (file_exists($filename)) {
            require_once($filename);
            return true;
        }
        // try Doctrine
        if (!self::$doctrineClassLoader) {
            define('DOCTRINE_ROOT', CONTRIB_PATH . DS);
            require_once(__DIR__ . '/contrib/Doctrine/Common/ClassLoader.php');
            self::$doctrineClassLoader = new \Doctrine\Common\ClassLoader();
        }
        if (self::$doctrineClassLoader->loadClass($className)) {
            return true;
        }
        // try module dir
        $module = SGA::getContext()->getModule();
        if ($module) {
            $filename = $module->getFullPath() . DS . $filename;
            if (file_exists($filename)) {
                require_once($filename);
                return true;
            }
        }
        throw new \Exception(sprintf(_('Classe não encontrada: %s'), $className));
    }
    
    /**
     * SGA exception handler
     * @param \Exception $exception
     */
    public static function onException(\Exception $exception) {
        $view = new \core\view\ErrorView();
        $context = SGA::getContext();
        $context->setParameter('exception', $exception);
        echo $view->render($context);
        exit();
    }
    
    /**
     * SGA error handler
     * @param \Exception $exception
     */
    public static function onError($number, $message, $file, $line) {
        $view = new \core\view\ErrorView();
        $context = SGA::getContext();
        $context->setParameter('error', array($number, $message, $file, $line));
        echo $view->render($context);
        exit();
    }

    /**
     * Retorna informacoes gerais sobre o sistema
     */
    public static function info() {
        ob_start();
        phpinfo();
        $info = ob_get_contents();
        ob_end_clean();
        return $info;
    }    

}

spl_autoload_register(array('core\SGA', 'load'));
set_exception_handler(array('core\SGA', 'onException'));
set_error_handler(array('core\SGA', 'onError'));
