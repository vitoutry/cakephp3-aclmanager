<?php

/**
 * CakePHP 3.x - Acl Manager
 * 
 * PHP version 5
 * 
 * Class AclController
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @category CakePHP3
 * 
 * @package AclManager\Controller
 * 
 * @author Ivan Amat <dev@ivanamat.es>
 * @copyright Copyright 2016, Iván Amat
 * @license MIT http://opensource.org/licenses/MIT
 * @link https://github.com/ivanamat/cakephp3-aclmanager
 */

namespace AclManager\Controller;

use Acl\Controller\Component\AclComponent;
use AclManager\Controller\AppController;
use Cake\Controller\ComponentRegistry;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;

class AclController extends AppController {

    /**
     * Components
     *
     * @var array
     */
    public $components = [
        'Acl' => [
            'className' => 'Acl.Acl'
        ],
        'AclManager' => [
            'className' => 'AclManager.AclManager'
        ]
    ];
    
    /**
     * Model
     *
     * @var NULL
     */
    public $model = NULL;
    
    
    /**
     * Initialize
     */
    public function initialize(){
        parent::initialize();
        
        /**
         * Initialize ACLs
         */
        $registry = new ComponentRegistry();
        $this->Acl = new AclComponent($registry, Configure::read('Acl'));
        
        /**
         * Loading required Model
         */
        $models = Configure::read('AclManager.models');
        foreach ($models as $model) {
            $this->loadModel($model);
        }
        $this->loadModel('Acl.Permissions');
        $this->loadModel('Acos');

        /**
         * Pagination
         */
        $aros = Configure::read('AclManager.aros');
        foreach ($aros as $aro) {

            $l = Configure::read("AclManager.{$aro}.limit");
            $limit = empty($l) ? 4 : $l;
            $this->paginate[$this->{$aro}->alias()] = array(
                'recursive' => -1,
                'limit' => $limit
            );
        }
        
        return null;
    }

    /**
     * AclManager main page
     */
    public function index() {
        
    }

    /**
     * Manage Permissions
     */
    public function permissions($model = 'Groups') {

        $this->model = $model;

        // Saving permissions
        if ($this->request->is('post') || $this->request->is('put')) {
            $perms = isset($this->request->data['Perms']) ? $this->request->data['Perms'] : array();
            foreach ($perms as $aco => $aros) {
                $action = str_replace(":", "/", $aco);
                foreach ($aros as $node => $perm) {
                    list($model, $id) = explode(':', $node);
                    $node = array('model' => $model, 'foreign_key' => $id);
                    if ($perm == 'allow') {
                        $this->Acl->allow($node, $action);
                    } elseif ($perm == 'inherit') {
                        $this->Acl->inherit($node, $action);
                    } elseif ($perm == 'deny') {
                        $this->Acl->deny($node, $action);
                    }
                }
            }
        }

        if (!$model || !in_array($model, Configure::read('AclManager.aros'))) {
            $m = Configure::read('AclManager.aros');
            $model = $m[0];
        }

        $Aro = $this->{$model};
        $arosRes = $this->paginate($Aro->alias());
        $aros = $this->_parseAros($arosRes);
        $permKeys = $this->_getKeys();

        /**
         * Build permissions info
         */
        $acosRes = $this->Acl->Aco->find('all', ['order' => 'lft ASC'])->contain(['Aros'])->toArray();
        $this->acos = $acos = $this->_parseAcos($acosRes);

        $perms = array();
        $parents = array();
        foreach ($acos as $key => $data) {
            $aco = & $acos[$key];
            $aco = array('Aco' => $data['Aco'], 'Aro' => $data['Aro'], 'Action' => array());
            $id = $aco['Aco']['id'];

            // Generate path
            if ($aco['Aco']['parent_id'] && isset($parents[$aco['Aco']['parent_id']])) {
                $parents[$id] = $parents[$aco['Aco']['parent_id']] . '/' . $aco['Aco']['alias'];
            } else {
                $parents[$id] = $aco['Aco']['alias'];
            }

            $aco['Action'] = $parents[$id];

            // Fetching permissions per ARO
            $acoNode = $aco['Action'];

            foreach ($aros as $aro) {
                $aroId = $aro[$Aro->alias()]['id'];
                $evaluate = $this->_evaluate_permissions($permKeys, array('id' => $aroId, 'alias' => $Aro->alias()), $aco, $key);
                $perms[str_replace('/', ':', $acoNode)][$Aro->alias() . ":" . $aroId . '-inherit'] = $evaluate['inherited'];
                $perms[str_replace('/', ':', $acoNode)][$Aro->alias() . ":" . $aroId] = $evaluate['allowed'];
            }
        }

        $this->request->data = array('Perms' => $perms);
        $this->set('model', $model);
        $this->set('aroAlias', $Aro->alias());
        $this->set('aroDisplayField', $Aro->displayField());
        $this->set(compact('acos', 'aros'));
    }


    /**
     * Update ACOs
     * Sets the missing actions in the database
     */
    public function updateAcos() {
        $resources = $this->_getAcos();
        $countActual = count($resources);

        $ab = $this->AclManager->acosBuilder();

        $newResources = $this->_getAcos();
        $countAcosBuilder = count($newResources);
        
        $count = (($countAcosBuilder + $countActual) ==  $countActual) ? 0 : ($countAcosBuilder - $countActual);
        
        $string = sprintf(__("%d ACOs have been created/updated"), $count);
        
        $this->Flash->success($string);
        $url = ($this->request->referer() == '/') ? ['plugin' => 'AclManager','controller' => 'Acl','action' => 'permissions'] : $this->request->referer();
        $this->redirect($url);
    }

    /**
     * Update AROs
     * Sets the missing AROs in the database
     */
    public function updateAros() {
	$arosCounter = $this->AclManager->arosBuilder();
        
        $this->Flash->success(sprintf(__("%d AROs have been created/updated"), $arosCounter));
        $url = ($this->request->referer() == '/') ? ['plugin' => 'AclManager','controller' => 'Acl','action' => 'permissions'] : $this->request->referer();
        $this->redirect($url);
    }

    /**
     * Delete all permissions (AROs)
     */
    public function revokePerms() {
        $conn = ConnectionManager::get('default');
        $stmt = $conn->execute('TRUNCATE TABLE aros_acos');
        $info = $stmt->errorInfo();
        
        if ($info != null && !empty($info)) {
            $this->Flash->success(__("All permissions dropped!"));
        } else {
            $this->Flash->error(__("Error while trying to drop permissions"));
        }
        
        // $this->Acl->allow(['Groups' => ['id' => 1]], 'controllers');
        // $this->Flash->success(__("Granted permissions to group with id 1"));
        $this->Acl->allow(['Roles' => ['id' => 1]], 'controllers');
        $this->Flash->success(__("Granted permissions to role with id 1"));
        
        $this->redirect(array("action" => "permissions"));
    }
    
    /**
     * Delete everything (ACOs and AROs)
     */
    public function drop() {
        $conn = ConnectionManager::get('default');
        $stmt1 = $conn->execute('TRUNCATE TABLE aros_acos');
        /*
        $info1 = $stmt1->errorInfo();
        if ($info1 != null && !empty($info1)) {
            $this->log($info1, LOG_ERR);
            $this->Flash->error($info1);
        }
        */
        $stmt2 = $conn->execute('TRUNCATE TABLE acos');
        /*
        $info2 = $stmt2->errorInfo();
        if ($info2 != null && !empty($info2)) {
            $this->log($info2, LOG_ERR);
            $this->Flash->error($info2);
        }
        */
        $stmt3 = $conn->execute('TRUNCATE TABLE aros');
        /*
        $info3 = $stmt3->errorInfo();
        if ($info3 != null && !empty($info3)) {
            $this->log($info3, LOG_ERR);
            $this->Flash->error($info3);
        }
        */
        if($this->AclManager->checkNodeOrSave('controllers', 'controllers', null)) {
            $string = __("The root node 'controllers' has been created");
            $this->log($string, LOG_INFO);
            $this->Flash->success($string);
        }

        $this->Flash->success(__("Both ACOs and AROs have been dropped"));
        $this->redirect(["action" => "permissions"]);
    }
    
    /**
     * Delete everything (ACOs and AROs)
     * 
     * TODO: Check $stmt->errorInfo();
     */
    public function defaults() {
        $conn = ConnectionManager::get('default');
        $stmt1 = $conn->execute('TRUNCATE TABLE aros_acos');
        /*
        $info1 = $stmt1->errorInfo();
        if ($info1 != null && !empty($info1)) {
            $this->log($info1, LOG_ERR);
            $this->Flash->error($info1);
        }
        */
        $stmt2 = $conn->execute('TRUNCATE TABLE acos');
        /*
        $info2 = $stmt2->errorInfo();
        if ($info2 != null && !empty($info2)) {
            $this->log($info2, LOG_ERR);
            $this->Flash->error($info2);
        }
        */
        $stmt3 = $conn->execute('TRUNCATE TABLE aros');
        /*
        $info3 = $stmt3->errorInfo();
        if ($info3 != null && !empty($info3)) {
            $this->log($info3, LOG_ERR);
            $this->Flash->error($info3);
        }
        */
        $this->Flash->success(__("Permissions ACOs and AROs have been dropped"));

        /**
         * Build and count acos
         */
        $this->AclManager->acosBuilder();
        $acos = $this->_getAcos();
        $this->Flash->success(sprintf(__("%d ACOs have been created"), count($acos)));
        
        /**
         * Build and count aros
         */
        $aros = $this->AclManager->arosBuilder();
        $this->Flash->success(sprintf(__("%d AROs have been created"), $aros));
        
        $this->Acl->allow(['Groups' => ['id' => 1]], 'controllers');
        $this->Flash->success(__("Granted permissions to group with id 1"));
        $this->Acl->allow(['Roles' => ['id' => 1]], 'controllers');
        $this->Flash->success(__("Granted permissions to role with id 1"));
        
        $this->Flash->success(__("Congratulations! Everything has been restored by default!"));
        
        $this->redirect(["action" => "permissions"]);
    }

    /**
     * Recursive function to find permissions avoiding slow $this->Acl->check().
     */
    private function _evaluate_permissions($permKeys, $aro, $aco, $aco_index) {

        $this->acoId = $aco['Aco']['id'];
        $result = $this->Acl->Aro->find('all', [
                    'contain' => ['Permissions' => function ($q) {
                            return $q->where(['aco_id' => $this->acoId]);
                        }
                            ],
                            'conditions' => [
                                'model' => $aro['alias'],
                                'foreign_key' => $aro['id']
                            ]
                        ])->toArray();

        $permissions = array_shift($result);
        $permissions = array_shift($permissions->permissions);

        $allowed = false;
        $inherited = false;
        $inheritedPerms = array();
        $allowedPerms = array();

        /**
         * Manually checking permission
         * Part of this logic comes from DbAcl::check()
         */
        foreach ($permKeys as $key) {
            if (!empty($permissions)) {
                if ($permissions[$key] == '-1') {
                    $allowed = false;
                    break;
                } elseif ($permissions[$key] == '1') {
                    $allowed = true;
                    $allowedPerms[$key] = 1;
                } elseif ($permissions[$key] == '0') {
                    $inheritedPerms[$key] = 0;
                }
            } else {
                $inheritedPerms[$key] = 0;
            }
        }

        if (count($allowedPerms) === count($permKeys)) {
            $allowed = true;
        } elseif (count($inheritedPerms) === count($permKeys)) {
            if ($aco['Aco']['parent_id'] == null) {
                $this->lookup +=1;
                $acoNode = (isset($aco['Action'])) ? $aco['Action'] : null;
                $aroNode = array('model' => $aro['alias'], 'foreign_key' => $aro['id']);
                $allowed = $this->Acl->check($aroNode, $acoNode);
                $this->acos[$aco_index]['evaluated'][$aro['id']] = array(
                    'allowed' => $allowed,
                    'inherited' => true
                );
            } else {
                /**
                 * Do not use Set::extract here. First of all it is terribly slow, 
                 * besides this we need the aco array index ($key) to cache are result.
                 */
                foreach ($this->acos as $key => $a) {
                    if ($a['Aco']['id'] == $aco['Aco']['parent_id']) {
                        $parent_aco = $a;
                        break;
                    }
                }
                // Return cached result if present
                if (isset($parent_aco['evaluated'][$aro['id']])) {
                    return $parent_aco['evaluated'][$aro['id']];
                }

                // Perform lookup of parent aco
                $evaluate = $this->_evaluate_permissions($permKeys, $aro, $parent_aco, $key);

                // Store result in acos array so we need less recursion for the next lookup
                $this->acos[$key]['evaluated'][$aro['id']] = $evaluate;
                $this->acos[$key]['evaluated'][$aro['id']]['inherited'] = true;

                $allowed = $evaluate['allowed'];
            }
            $inherited = true;
        }

        return array(
            'allowed' => $allowed,
            'inherited' => $inherited,
        );
    }

    /**
     * Returns permissions keys in Permission schema
     * @see DbAcl::_getKeys()
     */
    protected function _getKeys() {
        $keys = $this->Permissions->schema()->columns();
        $newKeys = array();
        foreach ($keys as $key) {
            if (!in_array($key, array('id', 'aro_id', 'aco_id'))) {
                $newKeys[] = $key;
            }
        }
        return $newKeys;
    }

    /**
     * Returns all the ACOs including their path
     */
    protected function _getAcos() {
        $acos = $this->Acl->Aco->find('all', array('order' => 'Acos.lft ASC', 'recursive' => -1))->toArray();
        $parents = array();
        foreach ($acos as $key => $data) {

            $aco = & $acos[$key];
            $aco = $aco->toArray();
            $id = $aco['id'];
            
            // Generate path
            if ($aco['parent_id'] && isset($parents[$aco['parent_id']])) {
                $parents[$id] = $parents[$aco['parent_id']] . '/' . $aco['alias'];
            } else {
                $parents[$id] = $aco['alias'];
            }
            $aco['action'] = $parents[$id];
        }
        return $acos;
    }

    /**
     * Returns an array with acos
     * @param Acos $acos Parse Acos entities and store into array formated
     * @return array 
     */
    private function _parseAcos($acos) {
        $cache = [];
        foreach ($acos as $aco) {
            $data['Aco'] = [
                'id' => $aco->id,
                'parent_id' => $aco->parent_id,
                'foreign_key' => $aco->foreign_key,
                'alias' => $aco->alias,
                'lft' => $aco->lft,
                'rght' => $aco->rght,
            ];
            if (isset($aco->model)) {
                $data['Aco']['model'] = $aco->model;
            }

            $d = [];
            foreach ($aco['aros'] as $aro) {
                $d[] = [
                    'id' => $aro->id,
                    'parent_id' => $aro->parent_id,
                    'model' => $aro->model,
                    'foreign_key' => $aro->foreign_key,
                    'alias' => $aro->alias,
                    'lft' => $aro->lft,
                    'rght' => $aro->rght,
                    'Permission' => [
                        'aro_id' => $aro->_joinData->aro_id,
                        'id' => $aro->_joinData->id,
                        'aco_id' => $aro->_joinData->aco_id,
                        '_create' => $aro->_joinData->_create,
                        '_read' => $aro->_joinData->_read,
                        '_update' => $aro->_joinData->_update,
                        '_delete' => $aro->_joinData->_delete,
                    ]
                ];
            }

            $data['Aro'] = $d;

            array_push($cache, $data);
        }

        return $cache;
    }

    /**
     * Returns an array with aros
     * @param Aros $aros Parse Aros entities and store into an array.
     * @return array 
     */
    private function _parseAros($aros) {
        $cache = array();
        foreach ($aros as $aro) {
            $data[$this->model] = [
                'id' => $aro->id,
                'created' => $aro->created,
                'modified' => $aro->modified
            ];

            if (isset($aro->group_id)) {
                $data[$this->model]['group_id'] = $aro->group_id;
            }
            if (isset($aro->role_id)) {
                $data[$this->model]['role_id'] = $aro->role_id;
            }
            if (isset($aro->name)) {
                $data[$this->model]['name'] = $aro->name;
            }
            if (isset($aro->username)) {
                $data[$this->model]['username'] = $aro->username;
            }

            array_push($cache, $data);
        }

        return $cache;
    }

}