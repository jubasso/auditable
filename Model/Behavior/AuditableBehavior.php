<?php
/**
 * Behavior to automagic log actions in aplications with authenticated users.
 * Store info about user which created entry, and user which modified an entry at the proper
 * table.
 * Also use a table called 'logs' (or other configurated for that) to save created/edited action
 * for continuous changes history.
 * 
 * Code comments in brazilian portuguese.
 * -----
 * 
 * Behavior que permite log automágico de ações executadas em uma aplicação com
 * usuários autenticados.
 * 
 * Armazena informações do usuário que criou um registro, e do usuário
 * que alterou o registro no próprio registro.
 * 
 * Ainda utiliza um modelo auxiliar Logger (ou outro configurado para isso) para registrar
 * ações de inserção/edição e manter um histórico contínuo de alterações.
 * 
 * PHP version > 5.3.1
 * 
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 * 
 * @copyright 2011-2012, Radig - Soluções em TI, www.radig.com.br
 * @link http://www.radig.com.br
 * @license http://www.opensource.org/licenses/mit-license.php The MIT License
 *
 * @package radig
 * @subpackage Auditable.Model.Behavior
 */
App::uses('AuditableConfig', 'Auditable.Lib');

class AuditableBehavior extends ModelBehavior
{
	/**
	 * Configurações correntes do Behavior
	 * 
	 * @var array
	 */
	public $settings = array();
	
	/**
	 * Configurações padrões do Behavior
	 * 
	 * @var array
	 */
	protected $defaults = array(
		'auditSql' => true,
		'skip' => array(),
		'fields' => array(
			'created' => 'created_by',
			'modified' => 'modified_by'
		)
	);
	
	protected $typesEnum = array(
		'create' => 1,
		'modify' => 2,
		'delete' => 3
	);
	
	/**
	 * ID do usuário ativo
	 * 
	 * @var mixed Pode ser um int, string (uuid) ou qualquer outro campo tipo de campo
	 * primário.
	 */
	protected $activeUserId = null;
	
	/**
	 * Referência para o modelo que persistirá
	 * as informações
	 * 
	 * @var Model
	 */
	protected $Logger = null;
	
	/**
	 * Guarda o instântaneo do registro antes
	 * de ser atualizado. O valor é usado após
	 * confirmação da alteração no registro e depois
	 * é limpado deste 'cache'
	 * 
	 * @var array
	 */
	protected $snapshots = array();
	
	/**
	 *  
	 */
	public function __construct() {
		parent::__construct();
		
		$this->activeUserId =& AuditableConfig::$userId;
		$this->Logger =& AuditableConfig::$Logger;
	}
	
	/**
	 * Chamado pelo CakePHP para iniciar o behavior
	 *
	 * @param Model $Model
	 * @param array $config
	 * 
	 * Opções de configurações:
	 *   auditSql       : bool. Habilita ou não o log das queries
	 *   skip           : array. Lista com nome das ações que devem ser ignoradas pelo log.
	 *   fields			: array. Aceita os dois índices abaixo
	 *     - created	: string. Nome do campo presente em cada modelo para armazenar quem criou o registro
	 *     - modified	: string. Nome do campo presente em cada modelo para armazenar quem alterou o registro
	 */
	public function setup(&$Model, $config = array())
	{
		if (!is_array($config))
		{
			$config = array();
		}
		
		$this->settings[$Model->alias] = array_merge($this->defaults, $config);

		if($this->settings[$Model->alias]['auditSql'])
		{
			// Força o recurso de salvar query, idependente do modo da aplicação
			$ds = $Model->getDataSource();
			$ds->fullDebug = true;
		}
	}
	
	/**
	 * Passa referência para modelo responsável por persistir os logs
	 * 
	 * @param Model $Model
	 * @param Logger $L 
	 */
	public function setLogger(&$Model, Logger &$L)
	{
		$this->Logger = $L;
	}
	
	/**
	 * Permite a definição do usuário ativo.
	 * 
	 * @param int $userId
	 */
	public function setActiveUser(&$Model, $userId)
	{
		if(empty($userId))
			return false;
		
		$this->activeUserId = $userId;
		
		return true;
	}
	
	/**
	 *
	 * @param Model $Model
	 * @return bool
	 */
	public function beforeSave(&$Model)
	{
		parent::beforeSave($Model);
		
		$action = ((isset($Model->data[$Model->alias]['id']) && !empty($Model->data[$Model->alias]['id'])) || !empty($Model->id)) ? 'modify' : 'create';
		
		$this->logResponsible($Model, $action);
		
		if($action == 'modify')
		{
			$this->takeSnapshot($Model);
		}
		
		return true;
	}
	
	/**
	 * 
	 * 
	 * @param Model $Model
	 * @param bool $created
	 * @return bool
	 */
	public function afterSave(&$Model, $created)
	{
		parent::afterSave($Model, $created);
		
		$action = $created ? 'create' : 'modify';
		
		$this->logQuery($Model, $action);
		
		return true;
	}
	
	/**
	 * 
	 * 
	 * @param Model $Model
	 * @param bool $cascade
	 */
	public function beforeDelete($Model, $cascade = true)
	{
		parent::beforeDelete($Model, $cascade);
		
		$this->takeSnapshot($Model);
		
		return true;
	}
	
	/**
	 * 
	 * 
	 * @param Model $Model
	 */
	public function afterDelete($Model)
	{
		parent::afterDelete($Model);
		
		$this->logQuery($Model, 'delete');
	}

	/**
	 * Recupera as definições para o modelo corrente
	 * 
	 * @param Model $Model
	 */
	public function settings(&$Model)
	{
		return $this->settings[$Model->alias];
	}
	
	/**
	 * Altera dados que estão sendo salvos para incluir responsável pela
	 * ação.
	 * 
	 * @param Model $Model
	 * @param bool $create
	 */
	protected function logResponsible(&$Model, $create = true)
	{
		if(empty($this->activeUserId))
		{
			return;
		}
		
		$createdByField = $this->settings[$Model->alias]['fields']['created'];
		$modifiedByField = $this->settings[$Model->alias]['fields']['modified'];
		
		if($create && $Model->schema($createdByField) !== null)
		{
			$Model->data[$Model->alias][$createdByField] = $this->activeUserId;
		}
		
		if(!$create && $Model->schema($modifiedByField) !== null)
		{
			$Model->data[$Model->alias][$modifiedByField] = $this->activeUserId;
		}
	}
	
	/**
	 * Guarda um instântaneo do registro que está sendo alterado
	 * 
	 * @param Model $Model
	 * 
	 * @return void
	 */
	protected function takeSnapshot(&$Model)
	{
		if(isset($Model->data[$Model->alias]['id']) && !empty($Model->data[$Model->alias]['id']))
		{
			$id = $Model->data[$Model->alias]['id'];
		}
		else
		{
			$id = $Model->id;
		}
		
		$aux = $Model->find('first', array(
			'conditions' => array("{$Model->alias}.id" => $id),
			'recursive' => -1
			)
		);
		
		$this->snapshots[$Model->alias] = $aux[$Model->alias];
	}
	
	/**
	 * Constrói e persiste informações relevantes sobre a transação através
	 * do Modelo Logger
	 * 
	 * @param Model $Model
	 * @param string $action
	 */
	protected function logQuery(&$Model, $action = 'create')
	{
		// Se não houver modelo configurado para salvar o log, aborta
		if($this->checkLogModels() === false)
		{
			CakeLog::write(LOG_WARNING, __d('auditable', "You need to define AuditableConfig::$Logger"));
			return;
		}

		switch($action)
		{
			case 'create':
				$diff = array();
				
				if(isset($Model->data[$Model->alias]))
					$diff = $Model->data[$Model->alias];
				
				break;
			
			case 'modify':
				$diff = $this->diffRecords($this->snapshots[$Model->alias], $Model->data[$Model->alias]);
				break;
			
			case 'delete':
				$diff = $this->snapshots[$Model->alias];
				break;
		}
		
		// Remoção dos campos ignorados
		foreach($this->settings[$Model->alias]['skip'] as $field)
		{
			if(isset($diff[$field]))
				unset($diff[$field]);
		}
		
		$encoded = $this->buildEncodedMessage($action, $diff);
		
		$statement = $this->getQuery($Model);
		
		$toSave = array(
			'Logger' => array(
				'user_id' => $this->activeUserId ?: 0,
				'model_alias' => $Model->alias,
				'model_id' => $Model->id,
				'type' => $this->typesEnum[$action] ?: 0,
			),
			'LogDetail' => array(
				'difference' => $encoded,
				'statement' => $statement,
			)
		);
		
		// Salva a entrada nos logs. Caso haja falha, usa o Log default do Cake para registrar a falha
		if($this->Logger->saveAssociated($toSave) === false)
		{
			CakeLog::write(LOG_WARNING, sprintf(__d('auditable', "Can't save log entry for statement: \"%s'\""), $statement));
		}
	}
	
	/**
	 * Codifica as alterações no registro (ou dados de criação) em um formato
	 * serializado, que é passado como primeiro parâmetro da função.
	 * Caso não seja passado uma função válida, a serialize padrão é usada.
	 * 
	 * 
	 * @param string $action create|modify|delete
	 * @param array $data Dados do registro
	 * 
	 * @return string Dados $data serializados
	 */
	private function buildEncodedMessage($action, $data)
	{
		$encode = array();
		
		switch($action)
		{
			case 'modify':
				foreach($data as $field => $changes)
					$encode[$field] = $changes;
				
				break;
				
			case 'create':
			case 'delete':
				foreach($data as $field => $value)
					$encode[$field] = $value;
				break;
		}
		
		$func = 'serialize';
		
		if(is_callable(AuditableConfig::$serialize))
		{
			$func = AuditableConfig::$serialize;
		}
		
		return call_user_func($func, $encode);
	}
	
	/**
	 * Recupera a última query executada pelo Modelo->DataSource
	 * e a retorna.
	 * 
	 * @param Model $Model
	 * @return string $statement
	 */
	private function getQuery($Model)
	{
		$ds = $Model->getDataSource();
		$statement = '';
		
		if(method_exists($ds, 'getLog'))
		{
			$logs = $ds->getLog();
			$logs = array_pop($logs['log']);
			$statement = $logs['query'];
		}
		
		return $statement;
	}
	
	/**
	 * Computa as alterações no registro e retorna um array formatado
	 * com os valores antigos e novos
	 * 
	 * 'campo' => array('old' => valor antigo, 'new' => valor novo)
	 * 
	 * @param array $old
	 * @param array $new
	 * 
	 * @return array $formatted
	 */
	private function diffRecords($old, $new)
	{
		$diff = Set::diff($old, $new);
		$formatted = array();
		
		foreach($diff as $key => $value)
		{
			if(!isset($old[$key]) || !isset($new[$key]))
				continue;
			
			$formatted[$key] = array('old' => $old[$key], 'new' => $new[$key]);
		}
		
		return $formatted;
	}

	/**
	 * Verifica e prepara os modelos utilizados para salvar os logs
	 * para que não haja recursão infinita.
	 * 
	 * @return bool
	 */
	private function checkLogModels()
	{
		if(!($this->Logger instanceof Model))
		{
			return false;
		}

		if($this->Logger->Behaviors->attached('Auditable'))
		{
			$this->Logger->Behaviors->unload('Auditable');
		}

		if(!isset($this->Logger->LogDetail))
		{
			return false;
		}

		if($this->Logger->LogDetail->Behaviors->attached('Auditable'))
		{
			$this->Logger->LogDetail->Behaviors->unload('Auditable');
		}

		return true;
	}
}