<?php
namespace OCA\DuplicateFinder\Command;

use OC\Core\Command\Base;
use OCP\Encryption\IManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IPreview;
use OCP\IUser;
use OCP\IUserManager;
use OCP\AppFramework\Http\DataResponse;
use OCA\Files\Helper;
use OC\Files\Filesystem;
use Exception;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FindDuplicates extends Base {

	/** @var IUserManager */
	protected $userManager;

	/** @var IRootFolder */
	protected $rootFolder;

	/** @var OutputInterface */
	protected $output;

	/** @var IManager */
	protected $encryptionManager;

	public function __construct(IRootFolder $rootFolder,
								IUserManager $userManager,
								IManager $encryptionManager) {
		parent::__construct();
		$this->userManager = $userManager;
		$this->rootFolder = $rootFolder;
		$this->encryptionManager = $encryptionManager;
	}

	protected function configure() {
		$this
			->setName('duplicates:find-all')
			->setDescription('Find all duplicates files')
			->addOption('recursive', 'r', InputOption::VALUE_OPTIONAL, 'scan folder recursively')
			->addOption('user','u', InputOption::VALUE_OPTIONAL, 'scan files of the specified user')
			->addOption('path','p', InputOption::VALUE_OPTIONAL, 'limit scan to this path, eg. --path="/alice/files/Photos"');

		parent::configure();
	}
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->output = $output;
		if ($this->encryptionManager->isEnabled()) {
			$this->output->writeln('Encryption is enabled. Aborted.');
			return 1;
		}
		$inputPath = $input->getOption('path');
		$user = $input->getOption('user');
		if($user){
			$files = $this->readFiles($user, $inputPath);
			$this->checkDuplicates($files, $user);
		}else{
			$this->userManager->callForSeenUsers(function (IUser $user) {
				$files = $this->readFiles($user->getUID(), $inputPath);
				$this->checkDuplicates($files, $user->getUID());
			});
		}

		return 0;
	}
	private function checkDuplicates($files, $username){
		$this->output->writeln("Start scan... user: " . $username);

		$results = \OCA\Files\Helper::formatFileInfos($files);

		$sizeArr = array();
        foreach ($results as $key => $result) {
			$path = $this->getRelativePath($files[$key]->getPath()). $result['name'];
            $sizeArr[$path] = $result['size'];
		}
		$duplicates = array_intersect($sizeArr, array_diff_assoc($sizeArr, array_unique($sizeArr)));

        $hashArr = array();
        foreach($duplicates as $filePath=>$size){
            if($info = Filesystem::getLocalFile($filePath)) {
                $fileHash = hash_file('md5', $info);
				if($fileHash){
					$hashArr[$filePath] = $fileHash;
				}
            }
        }

        $duplicatesHash = array_intersect($hashArr, array_diff_assoc($hashArr, array_unique($hashArr)));
        asort($duplicatesHash);


		$previousHash = 0;
		foreach($duplicatesHash as $filePath=>$fileHash) {
			if($previousHash != $fileHash){
				$this->output->writeln("\/----".$fileHash."---\/");
			}
			$this->output->writeln($filePath);
			$previousHash = $fileHash;
		}
		if(count($duplicatesHash) == 0){
			$this->output->writeln("No duplicate file");
		}
		$this->output->writeln("...end scan");
	}
	private function readFiles(string $user, $path){
		if(!$path){
			$path = '';
		}
		\OC_Util::tearDownFS();
		if(!\OC_Util::setupFS($user)){
			throw new Exception("Utilisateur inconnu", 1);
		}
		return $this->getFilesRecursive($path);
	}

	private function getFilesRecursive($path , & $results = []) {
		$files = Filesystem::getDirectoryContent($path);
		foreach($files as $file) {
			if ($file->getType() === 'dir') {
				$this->getFilesRecursive($path . '/' . $file->getName(), $results);
			} else {
				$results[] = $file;
			}
		}

		return $results;
	}
	private function getRelativePath($path) {
		$path = Filesystem::getView()->getRelativePath($path);
		return substr($path, 0, strlen($path) - strlen(basename($path)));
	}
}
