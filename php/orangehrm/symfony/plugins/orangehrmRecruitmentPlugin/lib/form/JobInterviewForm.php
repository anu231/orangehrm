<?php

/**
 * OrangeHRM is a comprehensive Human Resource Management (HRM) System that captures
 * all the essential functionalities required for any enterprise.
 * Copyright (C) 2006 OrangeHRM Inc., http://www.orangehrm.com
 *
 * OrangeHRM is free software; you can redistribute it and/or modify it under the terms of
 * the GNU General Public License as published by the Free Software Foundation; either
 * version 2 of the License, or (at your option) any later version.
 *
 * OrangeHRM is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program;
 * if not, write to the Free Software Foundation, Inc., 51 Franklin Street, Fifth Floor,
 * Boston, MA  02110-1301, USA
 *
 */
class JobInterviewForm extends BaseForm {

	public $candidateName;
	public $vacancyName;
	public $numberOfInterviewers = 5;
	public $candidateVacancyId;
	public $selectedAction;
	public $candidateId;
        public $vacancyId;
	private $candidateService;
	private $selectedCandidateVacancy;
	private $interviewService;

	/**
	 *
	 * @return <type>
	 */
	public function getCandidateService() {
		if (is_null($this->candidateService)) {
			$this->candidateService = new CandidateService();
			$this->candidateService->setCandidateDao(new CandidateDao());
		}
		return $this->candidateService;
	}

	public function getInterviewService() {
		if (is_null($this->interviewService)) {
			$this->interviewService = new JobInterviewService();
			$this->interviewService->setJobInterviewDao(new JobInterviewDao());
		}
		return $this->interviewService;
	}

	public function configure() {

		$this->candidateVacancyId = $this->getOption('candidateVacancyId');
		$this->selectedAction = $this->getOption('selectedAction');
		$this->id = $this->getOption('id');
		$this->interviewId = $this->getOption('interviewId');

		if ($this->candidateVacancyId > 0 && $this->selectedAction == WorkflowStateMachine::RECRUITMENT_APPLICATION_ACTION_SHEDULE_INTERVIEW) {
			$this->selectedCandidateVacancy = $this->getCandidateService()->getCandidateVacancyById($this->candidateVacancyId);
			$this->vacancyId = $this->selectedCandidateVacancy->getVacancyId();
			$this->candidateName = $this->selectedCandidateVacancy->getCandidateName();
			$this->vacancyName = $this->selectedCandidateVacancy->getVacancyName();
		}
//creating widgets
		$this->setWidgets(array(
		    'name' => new sfWidgetFormInputText(),
		    'date' => new sfWidgetFormInputText(),
		    'time' => new sfWidgetFormInputText(),
		    'note' => new sfWidgetFormTextArea(),
		    'selectedInterviewerList' => new sfWidgetFormInputHidden(),
		));

		for ($i = 1; $i <= $this->numberOfInterviewers; $i++) {
			$this->setWidget('interviewer_' . $i, new sfWidgetFormInputText());
		}

		$inputDatePattern = sfContext::getInstance()->getUser()->getDateFormat();
		$this->setValidators(array(
		    'name' => new sfValidatorString(array('required' => true, 'max_length' => 100)),
		    'date' => new ohrmDateValidator(array('date_format' => $inputDatePattern, 'required' => false),
			    array('invalid' => 'Date format should be ' . strtoupper($inputDatePattern))),
		    'time' => new sfValidatorString(array('required' => false, 'max_length' => 30)),
		    'note' => new sfValidatorString(array('required' => false, 'max_length' => 255)),
		    'selectedInterviewerList' => new sfValidatorString(array('required' => false)),
		));
		for ($i = 1; $i <= $this->numberOfInterviewers; $i++) {
			$this->setValidator('interviewer_' . $i, new sfValidatorString(array('required' => false, 'max_length' => 100)));
		}

		$this->widgetSchema->setNameFormat('jobInterview[%s]');

		if ($this->id != null) {
			$this->setDefaultValues($this->id);
		}
	}

	private function setDefaultValues($interviewId) {

		$interview = $this->getInterviewService()->getInterviewById($interviewId);
		$this->setDefault('name', $interview->getInterviewName());
		$this->setDefault('date', $interview->getInterviewDate());
		$this->setDefault('time', $interview->getInterviewTime());
		$this->setDefault('note', $interview->getNote());

		$interviewers = $interview->getJobInterviewInterviewer();
		$this->setDefault('interviewer_1', $interviewers[0]->getEmployee()->getFullName());
		for ($i = 1; $i <= count($interviewers); $i++) {
			$this->setDefault('interviewer_' . $i, $interviewers[$i - 1]->getEmployee()->getFullName());
		}
		$this->setDefault('selectedInterviewerList', count($interviewers));
	}

	public function save() {

		$interviewArray = array();
		if (empty($this->interviewId)) {
			$newJobInterview = new JobInterview();
			$newCandidateHistory = new CandidateHistory();
			$interviewArray = $this->getValue('selectedInterviewerList');
			$selectedInterviewerArrayList = explode(",", $interviewArray);
		} else {
			$selectedInterviewerList = $this->getValue('selectedInterviewerList');
			$selectedInterviewerArrayList = explode(",", $selectedInterviewerList);
			$newJobInterview = $this->getInterviewService()->getInterviewById($this->interviewId);
			$existingInterviewers = $newJobInterview->getJobInterviewInterviewer();

			$idList = array();
			if ($existingInterviewers[0]->getInterviewerId() != "") {
				foreach ($existingInterviewers as $existingInterviewer) {
					$id = $existingInterviewer->getInterviewerId();
					if (!in_array($id, $selectedInterviewerArrayList)) {
						$existingInterviewer->delete();
					} else {
						$idList[] = $id;
					}
				}
			}


			$selectedInterviewerArrayList = array_diff($selectedInterviewerArrayList, $idList);
			$newList = array();
			foreach ($selectedInterviewerArrayList as $elements) {
				$newList[] = $elements;
			}
			$selectedInterviewerArrayList = $newList;
						
		}
		$interviewId = $this->saveInterview($newJobInterview, $selectedInterviewerArrayList);
		if (empty($this->interviewId)) {
			$this->saveCandidateHistory($newCandidateHistory, $interviewId);
		}
	}

	protected function saveInterview($newJobInterview, $selectedInterviewerArrayList) {

		$name = $this->getValue('name');
		$date = $this->getValue('date');
		$time = $this->getValue('time');
		$note = $this->getValue('note');

		$newJobInterview->setInterviewName($name);
		$newJobInterview->setInterviewDate($date);
		$newJobInterview->setInterviewTime($time);
		$newJobInterview->setNote($note);
		$newJobInterview->setCandidateVacancyId($this->candidateVacancyId);
		if(!empty ($this->interviewId)){
		 $this->getInterviewService()->updateJobInterview($newJobInterview);
		} else {
			$newJobInterview->save();
		}

		$interviewId = $newJobInterview->getId();
		if (!empty($selectedInterviewerArrayList)) {
			for ($i = 0; $i < count($selectedInterviewerArrayList); $i++) {
				$newInterviewer = new JobInterviewInterviewer();
				$newInterviewer->setInterviewerId($selectedInterviewerArrayList[$i]);
				$newInterviewer->setInterviewId($interviewId);
				$newInterviewer->save();
			}
		}

		return $interviewId;
	}

	protected function saveCandidateHistory($newCandidateHistory, $interviewId) {

		$newCandidateHistory->setAction($this->selectedAction);
		$newCandidateHistory->setCandidateId($this->candidateId);

		$empNumber = sfContext::getInstance()->getUser()->getEmployeeNumber();
		if ($empNumber == 0) {
			$empNumber = null;
		}

		$newCandidateHistory->setCandidateVacancyId($this->candidateVacancyId);
		$newCandidateHistory->setPerformedBy($empNumber);
		$newCandidateHistory->setPerformedDate(date('Y-m-d'));
		$newCandidateHistory->setNote($note = $this->getValue('note'));
		$newCandidateHistory->setInterviewId($interviewId);

		$result = $this->getCandidateService()->saveCandidateHistory($newCandidateHistory);
		$this->getCandidateService()->updateCandidateVacancy($this->selectedCandidateVacancy, $this->selectedAction);
	}

	public function getEmployeeListAsJson() {

		$jsonArray = array();
		$escapeCharSet = array(38, 39, 34, 60, 61, 62, 63, 64, 58, 59, 94, 96);
		$employeeService = new EmployeeService();
		$employeeService->setEmployeeDao(new EmployeeDao());

		$employeeList = $employeeService->getEmployeeList();
		$employeeUnique = array();
		foreach ($employeeList as $employee) {

			if (!isset($employeeUnique[$employee->getEmpNumber()])) {

				$name = $employee->getFirstName() . " " . $employee->getMiddleName();
				$name = trim(trim($name) . " " . $employee->getLastName());

				foreach ($escapeCharSet as $char) {
					$name = str_replace(chr($char), (chr(92) . chr($char)), $name);
				}

				$employeeUnique[$employee->getEmpNumber()] = $name;
				$jsonArray[] = array('name' => $name, 'id' => $employee->getEmpNumber());
			}
		}

		$jsonString = json_encode($jsonArray);

		return $jsonString;
	}

}

