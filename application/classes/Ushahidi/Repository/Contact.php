<?php defined('SYSPATH') OR die('No direct access allowed.');

/**
 * Ushahidi Contact Repository
 *
 * @author     Ushahidi Team <team@ushahidi.com>
 * @package    Ushahidi\Application
 * @copyright  2014 Ushahidi
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU Affero General Public License Version 3 (AGPL3)
 */

use Ushahidi\Core\SearchData;
use Ushahidi\Core\Entity;
use Ushahidi\Core\Entity\Contact;
use Ushahidi\Core\Entity\ContactRepository;
use Ushahidi\Core\Usecase\CreateRepository;
use Ushahidi\Core\Usecase\UpdateRepository;
use Ushahidi\Core\Usecase\SearchRepository;
use Ushahidi\Core\Traits\UserContext;
use Ushahidi\Core\Traits\AdminAccess;

class Ushahidi_Repository_Contact extends Ushahidi_Repository implements
	ContactRepository, CreateRepository, UpdateRepository, SearchRepository
{
	use UserContext;
	use AdminAccess;
	// Use Event trait to trigger events
	// use \Ushahidi\Core\Traits\Event;

	protected function getId(Entity $entity)
	{
		$result = $this->selectQuery()
			->where('user_id', '=', $entity->user_id)
			->and_where('contact', '=', $entity->contact)
			->execute($this->db);
		return $result->get('id', 0);
	}

	// Ushahidi_Repository
	protected function getTable()
	{
		return 'contacts';
	}

	// CreateRepository
	// ReadRepository
	public function getEntity(Array $data = null)
	{
		return new Contact($data);
	}

	// SearchRepository
	public function getSearchFields()
	{
		return [
			'contact', 'type', 'user', 'data_provider'
		];
	}

	// Ushahidi_Repository
	protected function setSearchConditions(SearchData $search)
	{
		$query = $this->search_query;

		$user = $this->getUser();

		// Limit search to user's records unless they are admin
		// or if we get user=me as a search param
		if (! $this->isUserAdmin($user) || $search->user === 'me') {
			$search->user = $this->getUserId();
		}

		foreach ([
			'user',
		] as $fk)
		{
			if ($search->$fk)
			{
				$query->where("contacts.{$fk}_id", '=', $search->$fk);
			}
		}

		foreach ([
			'type',
			'data_provider',
			'contact'
		] as $key)
		{
			if ($search->$key)
			{
				$query->where("contacts.{$key}", '=', $search->$key);
			}
		}
	}

	// CreateRepository
	public function create(Entity $entity)
	{
		$id = $this->getId($entity);

		// @todo perhaps allow fields for existing entity to be defined when an entity is being created
		if ($id) {
			// No need to insert a new record.
			// Instead return the id of the contact that exists
			return $id;
		}

		$state = [
			'created'  => time(),
		];

		return parent::create($entity->setState($state));
	}

	// UpdateRepository
	public function update(Entity $entity)
	{
		$state = [
			'updated'  => time(),
		];

		return parent::update($entity->setState($state));
	}

	// ContactRepository
	public function getByContact($contact, $type)
	{
		return $this->getEntity($this->selectOne(compact('contact', 'type')));
	}

    public function isInTargetedSurvey($contact_id)
    {
        $query = DB::select('targeted_survey_state.contact_id', 'targeted_survey_state.form_id')
            ->from('targeted_survey_state')
            ->where('contact_id', '=', $contact_id)
			->and_where('survey_status', '!=', Entity\TargetedSurveyState::SURVEY_FINISHED);

        if($query->execute($this->db)->count() > 0)
        {
            Kohana::$log->add(Log::INFO, 'Contact is in a targeted survey: contact_id#'.print_r($contact_id, true));
            return true;
        }
        Kohana::$log->add(Log::INFO, 'Contact is NOT in a targeted survey: contact_id#'.print_r($contact_id, true));
        return false;
    }

	/**
	 * @param $contact_id
	 * @return FALSE or a post_id to reference in the message
	 */
    public function hasPostOutsideOfTargetedSurvey($contact_id)
	{
		$query_posts = DB::select(DB::expr('DISTINCT (messages.post_id) as post_id'))
			->from('messages')
			->where("messages.post_id", 'NOT IN',
				DB::query
				(
					Database::SELECT, 'select targeted_survey_state.post_id from targeted_survey_state where contact_id = :contact'
				)
				->bind(':contact', $contact_id))
			->where(DB::expr('messages.contact_id'), '=', $contact_id);

		$post_ids_in_messages = $query_posts->execute($this->db)->as_array(null, 'post_id');
		if (count($post_ids_in_messages) >= 1) {
			return $post_ids_in_messages[0];
		}
		return false;
	}

	// ContactRepository
	public function getNotificationContacts($set_id, $limit = false, $offset = 0)
	{
		$query = DB::select('contacts.id', 'contacts.type', 'contacts.contact')
			->distinct(TRUE)
			->from('contacts')
			->join('notifications')
			->on('contacts.user_id', '=', 'notifications.user_id')
			->where('contacts.can_notify', '=', '1');

		if ($set_id) {
			$query->and_where('set_id', '=', $set_id);
		}

		if ($limit) {
			$query->limit($limit);
		}

		if ($offset) {
			$query->offset($offset);
		}

		$results =  $query->execute($this->db);

		return $this->getCollection($results->as_array());
	}

}
