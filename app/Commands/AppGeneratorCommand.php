<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use function Laravel\Prompts\text;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\confirm;

class AppGeneratorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Build an connectivity application template';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->printBanner();

        $projectName = $this->getProjectName();

        $this->info("Project name: {$projectName}");

        $selectedServices = $this->selectServices();

        $useBookingkitNetwork = $this->confirmBookingkitNetwork();

        $this->createProject($projectName, $selectedServices, $useBookingkitNetwork);
    }

    /**
     * Confirm if user wants to connect to bookingkit network.
     */
    private function confirmBookingkitNetwork(): bool
    {
        $this->line('');
        return confirm(
            label: 'Do you want to connect to the bookingkit network?',
            default: true,
            hint: 'If yes, will use external bookingkit-network. If no, will create project-specific network.'
        );
    }

    /**
     * Create the project folder and docker-compose.yml file.
     */
    private function createProject(string $projectName, array $selectedServices, bool $useBookingkitNetwork): void
    {
        $this->info("📁 Creating project folder: {$projectName}");
        
        // Create project directory
        if (!mkdir($projectName, 0755, true)) {
            $this->error("❌ Failed to create project directory: {$projectName}");
            return;
        }
        
        // Clone the repository first (into empty directory)
        $this->cloneRepository($projectName);
        
        $this->info("🐳 Creating docker-compose.yml...");
        
        // Get the stubs path
        $stubsPath = __DIR__ . '/../Stubs';
        
        // Start with app.stub (always included)
        $appStubPath = $stubsPath . '/app.stub';
        if (!file_exists($appStubPath)) {
            $this->error("❌ App stub not found: {$appStubPath}");
            return;
        }
        
        $composeContent = file_get_contents($appStubPath);
        
        // Add queue worker if selected
        if (in_array('queue', $selectedServices)) {
            $this->info("⏳ Adding queue worker service...");
            $queueStubPath = $stubsPath . '/queue.stub';
            if (file_exists($queueStubPath)) {
                $queueContent = file_get_contents($queueStubPath);
                $composeContent .= "\n" . $queueContent;
            } else {
                $this->warn("⚠️  Queue stub not found: {$queueStubPath}");
            }
        }
        
        // Add selected services
        foreach ($selectedServices as $service) {
            // Skip app and queue as they're handled separately
            if (in_array($service, ['app', 'queue'])) {
                continue;
            }
            
            $stubFile = $stubsPath . '/' . $service . '.stub';
            if (!file_exists($stubFile)) {
                $this->warn("⚠️  Service stub not found: {$stubFile}");
                continue;
            }
            
            $serviceContent = file_get_contents($stubFile);
            $composeContent .= "\n" . $serviceContent;
        }
        
        // Add volumes section if services are selected
        if (!empty($selectedServices)) {
            $namedVolumes = [];
            
            // Check each service for named volumes
            foreach ($selectedServices as $service) {
                $stubFile = $stubsPath . '/' . $service . '.stub';
                if (file_exists($stubFile)) {
                    $stubContent = file_get_contents($stubFile);
                    
                    // Find the volumes section
                    if (preg_match('/volumes:\s*\n((?:\s+-\s+[^\n]+\n?)*)/', $stubContent, $volumeMatches)) {
                        $volumeSection = $volumeMatches[1];
                        
                        // Extract named volumes from the volumes section
                        if (preg_match_all("/\s+-\s+'([^']+):[^']*'/", $volumeSection, $matches)) {
                            foreach ($matches[1] as $volumeName) {
                                // Check if it's a named volume (not a relative path like ./)
                                if (!str_starts_with($volumeName, './') && !str_starts_with($volumeName, '../') && !str_starts_with($volumeName, '/')) {
                                    // Replace any placeholder with project name
                                    $projectVolumeName = str_replace(['connectivity'], $projectName, $volumeName);
                                    $namedVolumes[$projectVolumeName] = $projectVolumeName;
                                }
                            }
                        }
                    }
                }
            }
            
            // Add volumes section only if there are named volumes
            if (!empty($namedVolumes)) {
                $composeContent .= "\nvolumes:";
                
                foreach ($namedVolumes as $volumeName) {
                    $composeContent .= "\n    {$volumeName}:";
                    $composeContent .= "\n        name: {$volumeName}";
                }
            }
        }
        
        // Add networks section
        $composeContent .= "\nnetworks:";
        $composeContent .= "\n    default:";
        
        if ($useBookingkitNetwork) {
            $composeContent .= "\n        name: bookingkit-network";
            $composeContent .= "\n        external: true";
        } else {
            $composeContent .= "\n        name: {$projectName}-network";
        }
        
        // Replace placeholders
        $composeContent = $this->replacePlaceholders($composeContent, $projectName);
        
        // Fix container names
        $projectUnderscore = str_replace('-', '_', $projectName);
        $projectDash = str_replace('_', '-', $projectName);
        
        // Build services array dynamically - always include app and add selected services
        $servicesForContainers = array_merge(['app'], $selectedServices);
        
        // Fix container names for all services
        foreach ($servicesForContainers as $service) {
            $composeContent = preg_replace(
                "/container_name:.*{$projectDash}-{$service}/",
                "container_name: {$projectUnderscore}_{$service}",
                $composeContent
            );
        }
        
        // Write the final docker-compose.yml file
        $composePath = $projectName . '/docker-compose.yml';
        if (file_put_contents($composePath, $composeContent) === false) {
            $this->error("❌ Failed to write docker-compose.yml file");
            return;
        }
        
        $this->info("✅ docker-compose.yml created successfully!");
        $this->info("📂 Project created in: " . realpath($projectName));
        
        // Configure .env file
        $this->configureEnvFile($projectName, $selectedServices);
        
        // Show final instructions
        $this->showFinalInstructions($projectName, $selectedServices);
    }

    /**
     * Configure the .env file with project-specific settings.
     */
    private function configureEnvFile(string $projectName, array $selectedServices): void
    {
        $this->info("⚙️  Configuring .env file...");
        
        $projectPath = realpath($projectName);
        $envExamplePath = $projectPath . '/.env.example';
        $envPath = $projectPath . '/.env';
        
        // Copy .env.example to .env
        if (!file_exists($envExamplePath)) {
            $this->error("❌ .env.example not found in project directory");
            return;
        }
        
        if (!copy($envExamplePath, $envPath)) {
            $this->error("❌ Failed to copy .env.example to .env");
            return;
        }
        
        // Read .env content
        $envContent = file_get_contents($envPath);
        
        // Replace APP_NAME
        $envContent = preg_replace('/APP_NAME=.*/', "APP_NAME={$projectName}", $envContent);
        
        // Determine database connection and host
        $dbConnection = 'mysql';
        $dbHost = $projectName . '-mysql';
        if (in_array('psql', $selectedServices)) {
            $dbConnection = 'pgsql';
            $dbHost = $projectName . '-postgres';
        }
        
        // Replace database settings
        $envContent = preg_replace('/DB_CONNECTION=.*/', "DB_CONNECTION={$dbConnection}", $envContent);
        $envContent = preg_replace('/DB_HOST=.*/', "DB_HOST={$dbHost}", $envContent);
        $envContent = preg_replace('/DB_DATABASE=.*/', "DB_DATABASE={$projectName}", $envContent);
        $envContent = preg_replace('/DB_USERNAME=.*/', "DB_USERNAME={$projectName}", $envContent);
        
        // Replace Redis host
        if (in_array('valkey', $selectedServices)) {
            $redisHost = $projectName . '-valkey';
            $envContent = preg_replace('/REDIS_HOST=.*/', "REDIS_HOST={$redisHost}", $envContent);
        }
        
        // Replace Mail host
        if (in_array('smtp', $selectedServices)) {
            $mailHost = $projectName . '-smtp';
            $envContent = preg_replace('/MAIL_HOST=.*/', "MAIL_HOST={$mailHost}", $envContent);
        }
        
        // Generate and set Laravel application key
        $appKey = 'base64:' . base64_encode(random_bytes(32));
        $envContent = preg_replace('/APP_KEY=.*/', "APP_KEY={$appKey}", $envContent);
        
        // Write updated .env file
        if (file_put_contents($envPath, $envContent) === false) {
            $this->error("❌ Failed to write .env file");
            return;
        }
        
        $this->info("✅ .env file configured successfully!");
        $this->info("🔑 Application key generated and set automatically!");
        
        // Show BOOKINGKIT_ROOT reminder in a prominent section
        $this->line('');
        $this->line('<fg=yellow>⚠️  IMPORTANT CONFIGURATION REQUIRED ⚠️</>');
        $this->line('<fg=yellow>══════════════════════════════════════════════════════════════</>');
        $this->line('<fg=yellow>Please update BOOKINGKIT_ROOT in .env file to the actual value!</>');
        $this->line('<fg=yellow>This is required for the application to work properly.</>');
        $this->line('<fg=yellow>══════════════════════════════════════════════════════════════</>');
        $this->line('');
    }

    /**
     * Show final setup instructions with selected services table.
     */
    private function showFinalInstructions(string $projectName, array $selectedServices): void
    {
        $this->line('');
        $this->line('<fg=green>══════════════════════════════════════════════════════════════</>');
        $this->line('<fg=green>                    Setup Complete!                           </>');
        $this->line('<fg=green>══════════════════════════════════════════════════════════════</>');
        
        $this->line('');
        $this->line('<fg=cyan>🚀 Next steps:</>');
        $this->line('1. 📁 Navigate to your project directory:');
        $this->line("   <fg=yellow>cd {$projectName}</>");
        
        $this->line('');
        $this->line('2. 🐳 Start the bookingkit containers first:');
        $this->line('   <fg=yellow>docker-compose -f /path/to/bookingkit/docker-compose.yml up -d</>');
        
        $this->line('');
        $this->line('3. 🚀 Start the Docker containers:');
        $this->line('   <fg=yellow>docker-compose up -d</>');
        
        $this->line('');
        $this->line('4. 🗄️  Run database migrations:');
        $this->line('   <fg=yellow>docker-compose exec app php artisan migrate</>');
        
        $this->line('');
        $this->line('<fg=cyan>🌐 Access your application:</>');
        $this->line('   <fg=green>📱 Main Application:</> http://localhost:8020');
        
        // Show additional URLs based on selected services
        foreach ($selectedServices as $service) {
            if ($service === 'smtp') {
                $this->line('   <fg=green>📧 Mailpit Dashboard:</> http://localhost:8030');
            }
        }
        
        $this->line('');
        $this->line('<fg=cyan>🛠️  Useful commands:</>');
        $this->line('   <fg=yellow>📋 docker-compose logs -f</> - View logs');
        $this->line('   <fg=yellow>⏹️  docker-compose down</> - Stop containers');
        $this->line('   <fg=yellow>🐚 docker-compose exec app bash</> - Access container shell');
        
        $this->line('');
        $this->line('<fg=green>🎉 Happy coding! 🚀</>');
    }

    /**
     * Clone the connectivity-app-template repository into the project folder.
     */
    private function cloneRepository(string $projectName): void
    {
        $this->info("📥 Cloning connectivity-app-template repository...");
        
        $repoUrl = 'https://git.bookingkit.de/bkconnect/connectivity-app-template.git';
        $projectPath = realpath($projectName);
        
        // Change to project directory
        $originalDir = getcwd();
        chdir($projectPath);
        
        // Clone the repository directly into the project folder
        $command = "git clone {$repoUrl} .";
        $output = [];
        $returnCode = 0;
        
        exec($command, $output, $returnCode);
        
        // Return to original directory
        chdir($originalDir);
        
        if ($returnCode === 0) {
            $this->info("✅ Repository cloned successfully!");
            
            // Remove .git directory to start fresh
            $gitDir = $projectPath . '/.git';
            if (is_dir($gitDir)) {
                $this->removeDirectory($gitDir);
                $this->info("🗑️  Removed .git directory - ready for fresh git initialization");
            }
        } else {
            $this->error("❌ Failed to clone repository. Error code: {$returnCode}");
            $this->error("Output: " . implode("\n", $output));
        }
    }

    /**
     * Replace placeholders in content with project name.
     */
    private function replacePlaceholders(string $content, string $projectName): string
    {
        // Convert project name to different formats
        $projectNameLower = strtolower($projectName);
        $projectNameUnderscore = str_replace('-', '_', $projectName);
        $projectNameDash = str_replace('_', '-', $projectName);

        // Get services from stub files
        $stubsPath = __DIR__ . '/../Stubs';
        $services = [];
        $stubFiles = glob($stubsPath . '/*.stub');
        foreach ($stubFiles as $stubFile) {
            $serviceKey = basename($stubFile, '.stub');
            if ($serviceKey !== 'docker-compose') {
                $services[] = $serviceKey;
            }
        }

        // Replace service-specific patterns
        foreach ($services as $service) {
            // Replace underscore patterns (container names)
            $content = str_replace(
                "connectivity_{$service}",
                "{$projectNameUnderscore}_{$service}",
                $content
            );

            // Replace dash patterns (service names)
            $content = str_replace(
                "connectivity-{$service}",
                "{$projectNameDash}-{$service}",
                $content
            );
        }

        // Replace general connectivity patterns
        $content = str_replace('connectivity/php:8.4-alpine', "{$projectNameLower}/php:8.4-alpine", $content);
        $content = str_replace('connectivity', $projectNameLower, $content);

        return $content;
    }

    /**
     * Get and validate the project name from user input.
     */
    private function getProjectName(): string
    {
        while (true) {
            $projectName = text('Enter your project name');

            // Check if project name is empty
            if (empty($projectName)) {
                $this->error('Project name cannot be empty. Please try again.');
                continue;
            }

            // Check if project name contains only valid characters
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $projectName)) {
                $this->error('Project name can only contain letters, numbers, hyphens, and underscores.');
                continue;
            }

            // Convert to slug format
            $slug = strtolower($projectName);

            // Check if directory already exists
            if (is_dir($slug)) {
                $this->warn("Directory '{$slug}' already exists.");
                $overwrite = $this->choice(
                    'Do you want to overwrite it?',
                    ['No', 'Yes'],
                    0
                );

                if ($overwrite === 'Yes') {
                    $this->info("Removing existing directory '{$slug}'...");
                    $this->removeDirectory($slug);
                    break;
                } else {
                    continue;
                }
            }

            break;
        }

        return strtolower($projectName);
    }

    /**
     * Remove directory and its contents recursively.
     */
    private function removeDirectory(string $path): void
    {
        if (is_dir($path)) {
            $files = array_diff(scandir($path), ['.', '..']);

            foreach ($files as $file) {
                $filePath = $path . DIRECTORY_SEPARATOR . $file;

                if (is_dir($filePath)) {
                    $this->removeDirectory($filePath);
                } else {
                    unlink($filePath);
                }
            }

            rmdir($path);
        }
    }

    /**
     * Print the ASCII banner.
     */
    private function printBanner(): void
    {
        $this->line('');
        $this->line('<fg=cyan>   ░█▀▀░█▀█░█▀█░█▀█░█▀▀░█▀▀░▀█▀░▀█▀░█░█░▀█▀░▀█▀░█░█</>');
        $this->line('<fg=cyan>   ░█░░░█░█░█░█░█░█░█▀▀░█░░░░█░░░█░░▀▄▀░░█░░░█░░░█░</>');
        $this->line('<fg=cyan>   ░▀▀▀░▀▀▀░▀░▀░▀░▀░▀▀▀░▀▀▀░░▀░░▀▀▀░░▀░░▀▀▀░░▀░░░▀░</>');
        $this->line('');
    }

    /**
     * Select Docker services from available stubs.
     */
    private function selectServices(): array
    {
        $this->line('');
        $this->line('<fg=cyan>Available Docker Services:</>');
        
        $services = [];
        $serviceDescriptions = [
            'mysql' => 'MySQL/MariaDB Database',
            'psql' => 'PostgreSQL Database',
            'smtp' => 'SMTP Server (Mailpit)',
            'valkey' => 'Redis/Valkey Cache',
            'queue' => 'Queue Worker'
        ];
        
        // Use embedded stubs instead of reading from files
        $services = $serviceDescriptions;
        
        $this->line('');
        $selectedServices = multiselect(
            label: 'Select the services you want to include:',
            options: array_values($services),
            default: ['PostgreSQL Database', 'Redis/Valkey Cache'],
            hint: 'Use space to select/deselect, arrow keys to navigate, enter to confirm.'
        );
        
        // Convert selected descriptions back to service keys
        $selectedKeys = [];
        foreach ($selectedServices as $selectedDescription) {
            $serviceKey = array_search($selectedDescription, $services);
            if ($serviceKey !== false) {
                $selectedKeys[] = $serviceKey;
            }
        }
        
        $this->line('');
        $this->line('<fg=green>Selected services:</>');
        foreach ($selectedKeys as $service) {
            if (isset($services[$service])) {
                $this->line("  ✓ {$services[$service]}");
            }
        }
        
        $this->line('');
        $confirmed = confirm(
            label: 'Do you want to proceed with these services?',
            default: true,
            hint: 'You can go back and modify your selection if needed.'
        );
        
        if (!$confirmed) {
            return $this->selectServices();
        }
        
        return $selectedKeys;
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}

