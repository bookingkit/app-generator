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

        $networkConfig = $this->confirmBookingkitNetwork($projectName);

        $bookingkitRoot = $this->getBookingkitRoot();

        $this->createProject($projectName, $selectedServices, $networkConfig, $bookingkitRoot);
    }

    /**
     * Get the BOOKINGKIT_ROOT path from user input.
     */
    private function getBookingkitRoot(): string
    {
        $this->line('');
        $bookingkitRoot = text(
            label: 'Enter the path to your bookingkit project',
            placeholder: '/Users/Arun/Developer/newBK/bookingkit-local',
            default: '/Users/Arun/Developer/newBK/bookingkit-local',
            hint: 'This should point to your bookingkit project directory containing docker-compose.dev.yml'
        );

        // Validate the path
        if (!is_dir($bookingkitRoot)) {
            $this->warn("⚠️  Warning: The directory '{$bookingkitRoot}' does not exist.");
            $confirm = $this->choice(
                'Do you want to continue anyway?',
                ['No', 'Yes'],
                0
            );
            
            if ($confirm === 'No') {
                return $this->getBookingkitRoot();
            }
        }

        // Check if it contains bookingkit files
        if (is_dir($bookingkitRoot) && !file_exists($bookingkitRoot . '/docker-compose.dev.yml')) {
            $this->warn("⚠️  Warning: 'docker-compose.dev.yml' not found in '{$bookingkitRoot}'.");
            $this->warn("This might not be a valid bookingkit project directory.");
            $confirm = $this->choice(
                'Do you want to continue anyway?',
                ['No', 'Yes'],
                0
            );
            
            if ($confirm === 'No') {
                return $this->getBookingkitRoot();
            }
        }

        // Validate that the path ends with 'bookingkit-local'
        if (!str_ends_with($bookingkitRoot, 'bookingkit-local')) {
            $this->warn("⚠️  Warning: The path should end with 'bookingkit-local'.");
            $this->warn("Expected format: /path/to/bookingkit-local");
            $this->warn("Current path: {$bookingkitRoot}");
            
            $confirm = $this->choice(
                'Do you want to continue anyway?',
                ['No', 'Yes'],
                0
            );
            
            if ($confirm === 'No') {
                return $this->getBookingkitRoot();
            }
        }

        return $bookingkitRoot;
    }

    /**
     * Confirm if user wants to connect to bookingkit network and get custom domain.
     */
    private function confirmBookingkitNetwork(string $projectName): array
    {
        $this->line('');
        $useBookingkitNetwork = confirm(
            label: 'Do you want to connect to the bookingkit network?',
            default: true,
            hint: 'If yes, will use external bookingkit-network and Traefik. If no, will create project-specific network.'
        );
        
        $customDomain = null;
        if ($useBookingkitNetwork) {
            $this->line('');
            $defaultDomain = $projectName . '.bookingkit.test';
            $customDomain = text(
                label: 'Enter custom domain for your application',
                default: $defaultDomain,
                hint: 'This will be used for Traefik routing (e.g., myapp.bookingkit.test)'
            );
        }
        
        return [
            'useBookingkitNetwork' => $useBookingkitNetwork,
            'customDomain' => $customDomain
        ];
    }

    /**
     * Create the project folder and docker-compose.yml file.
     */
    private function createProject(string $projectName, array $selectedServices, array $networkConfig, string $bookingkitRoot): void
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
        
        if ($networkConfig['useBookingkitNetwork']) {
            $composeContent .= "\n        name: bookingkit-network";
            $composeContent .= "\n        external: true";
        } else {
            $composeContent .= "\n        name: {$projectName}-network";
        }
        
        // Replace placeholders
        $composeContent = $this->replacePlaceholders($composeContent, $projectName);
        
        // Update Traefik domain if using bookingkit network
        if ($networkConfig['useBookingkitNetwork'] && $networkConfig['customDomain']) {
            $customDomain = $networkConfig['customDomain'];
            $composeContent = preg_replace(
                '/Host\(`[^`]+`\)/',
                "Host(`{$customDomain}`)",
                $composeContent
            );
        }
        
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
        $this->configureEnvFile($projectName, $selectedServices, $networkConfig['customDomain'] ?? null, $bookingkitRoot);
        
        // Show final instructions
        $this->showFinalInstructions($projectName, $selectedServices, $networkConfig['customDomain'] ?? null);
    }

    /**
     * Configure the .env file with project-specific settings.
     */
    private function configureEnvFile(string $projectName, array $selectedServices, ?string $customDomain = null, string $bookingkitRoot): void
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
        
        // Set APP_URL if custom domain is provided
        if ($customDomain) {
            $appUrl = "https://{$customDomain}";
            $envContent = preg_replace('/APP_URL=.*/', "APP_URL={$appUrl}", $envContent);
        }
        
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
        
        // Set BOOKINGKIT_ROOT
        $envContent = preg_replace('/BOOKINGKIT_ROOT=.*/', "BOOKINGKIT_ROOT={$bookingkitRoot}", $envContent);
        
        // Write updated .env file
        if (file_put_contents($envPath, $envContent) === false) {
            $this->error("❌ Failed to write .env file");
            return;
        }
        
        $this->info("✅ .env file configured successfully!");
        $this->info("🔑 Application key generated and set automatically!");
        $this->info("📁 BOOKINGKIT_ROOT configured: {$bookingkitRoot}");
        
        // Create configure-domain.sh script if custom domain is provided
        if ($customDomain) {
            $this->createConfigureDomainScript($projectName, $projectPath, $bookingkitRoot);
        }
    }

    /**
     * Create the configure-domain.sh script for certificate generation.
     */
    private function createConfigureDomainScript(string $projectName, string $projectPath, string $bookingkitRoot): void
    {
        $this->info("🔐 Creating configure-domain.sh script...");
        
        $scriptPath = $projectPath . '/configure-domain.sh';
        $domain = $projectName . '.bookingkit.test';
        
        $scriptContent = <<<SCRIPT
#!/usr/bin/env bash

# Script to generate certificates for the current project domain
# This extends the bookingkit certificate generation

set -e

# Get the current project name from the directory
DOMAIN="{$domain}"

# Set BOOKINGKIT_ROOT from script generation
BOOKINGKIT_ROOT="{$bookingkitRoot}"

echo "🌐 Domain: \$DOMAIN"
echo "📁 Bookingkit Root: \$BOOKINGKIT_ROOT"

# Check if bookingkit directory exists
if [ ! -d "\$BOOKINGKIT_ROOT" ]; then
    echo "❌ Bookingkit directory not found: \$BOOKINGKIT_ROOT"
    echo "Please update BOOKINGKIT_ROOT in .env file to the correct path."
    exit 1
fi

# Check if bookingkit scripts exist
BOOKINGKIT_HOSTS_FILE="\${BOOKINGKIT_ROOT}/hosts.txt"
BOOKINGKIT_GENERATE_SCRIPT="\${BOOKINGKIT_ROOT}/scripts/generate-certificates.sh"
BOOKINGKIT_INJECT_SCRIPT="\${BOOKINGKIT_ROOT}/scripts/inject-hosts.sh"

if [ ! -f "\$BOOKINGKIT_HOSTS_FILE" ]; then
    echo "❌ Bookingkit hosts.txt not found: \$BOOKINGKIT_HOSTS_FILE"
    exit 1
fi

if [ ! -f "\$BOOKINGKIT_GENERATE_SCRIPT" ]; then
    echo "❌ Bookingkit generate-certificates.sh not found: \$BOOKINGKIT_GENERATE_SCRIPT"
    exit 1
fi

if [ ! -f "\$BOOKINGKIT_INJECT_SCRIPT" ]; then
    echo "❌ Bookingkit inject-hosts.sh not found: \$BOOKINGKIT_INJECT_SCRIPT"
    exit 1
fi

echo "✅ All bookingkit files found"

# Step 1: Temporarily add domain to bookingkit hosts.txt and generate certificates
echo ""
echo "🔐 Step 1: Generating certificates using bookingkit scripts"

# Check if domain already exists in bookingkit hosts.txt
if grep -q "^\${DOMAIN}\$" "\$BOOKINGKIT_HOSTS_FILE"; then
    echo "✅ Domain \$DOMAIN already exists in bookingkit hosts.txt"
    DOMAIN_ALREADY_EXISTS=true
else
    echo "➕ Temporarily adding \$DOMAIN to bookingkit hosts.txt"
    echo "\$DOMAIN" >> "\$BOOKINGKIT_HOSTS_FILE"
    DOMAIN_ALREADY_EXISTS=false
fi

# Generate certificates using bookingkit script
echo "📜 Generating certificates..."
cd "\$BOOKINGKIT_ROOT"
bash scripts/generate-certificates.sh
cd - > /dev/null

# Remove the domain from bookingkit hosts.txt if we added it
if [ "\$DOMAIN_ALREADY_EXISTS" = false ]; then
    echo "🗑️  Removing \$DOMAIN from bookingkit hosts.txt"
    sed -i.bak "/^\${DOMAIN}\$/d" "\$BOOKINGKIT_HOSTS_FILE"
    rm -f "\$BOOKINGKIT_HOSTS_FILE.bak"
    echo "✅ Domain removed from bookingkit hosts.txt"
fi

echo "✅ Certificates generated using bookingkit scripts"

# Step 2: Add domain to /etc/hosts if not already present
echo ""
echo "📋 Step 2: Adding domain to /etc/hosts"
if grep -q "\$DOMAIN" /etc/hosts; then
    echo "✅ Domain \$DOMAIN already exists in /etc/hosts"
else
    echo "➕ Adding \$DOMAIN to /etc/hosts"
    echo "127.0.0.1 \$DOMAIN" | sudo tee -a /etc/hosts > /dev/null
    echo "✅ Domain added to /etc/hosts"
fi

# Step 3: Restart Traefik to pick up new certificates
echo ""
echo "🔄 Step 3: Restarting Traefik to pick up new certificates"
TRAEFIK_CONTAINER=\$(docker ps --filter "name=traefik" --format "{{.Names}}")
if [ -n "\$TRAEFIK_CONTAINER" ]; then
    echo "📦 Restarting Traefik container: \$TRAEFIK_CONTAINER"
    docker restart "\$TRAEFIK_CONTAINER"
    echo "✅ Traefik restarted successfully"
    
    # Wait a moment for Traefik to fully restart
    echo "⏳ Waiting for Traefik to fully restart..."
    sleep 5
else
    echo "⚠️  Traefik container not found. Please restart it manually:"
    echo "   docker restart traefik"
fi

# Step 4: Verify the domain is accessible
echo ""
echo "🔍 Step 4: Verifying domain resolution"
if grep -q "\$DOMAIN" /etc/hosts; then
    echo "✅ Domain \$DOMAIN found in /etc/hosts"
else
    echo "❌ Domain \$DOMAIN not found in /etc/hosts"
    echo "Please add it manually: echo '127.0.0.1 \$DOMAIN' | sudo tee -a /etc/hosts"
fi

# Step 5: Verify certificate is being used
echo ""
echo "🔍 Step 5: Verifying certificate usage"
echo "📜 Checking certificate for \$DOMAIN..."
CERT_INFO=\$(echo | openssl s_client -connect "\$DOMAIN:443" -servername "\$DOMAIN" 2>/dev/null | openssl x509 -noout -subject 2>/dev/null || echo "Failed to get certificate")
echo "📋 Certificate subject: \$CERT_INFO"

if echo "\$CERT_INFO" | grep -q "mkcert"; then
    echo "✅ Using mkcert certificate (correct)"
else
    echo "⚠️  Not using mkcert certificate"
    echo "   This might indicate Traefik is still using default certificate"
    echo "   Please check Traefik configuration and restart again if needed"
fi

echo ""
echo "🎉 Certificate generation and configuration complete!"
echo ""
echo "📋 Summary:"
echo "   Domain: \$DOMAIN"
echo "   Certificates: \$PROJECT_CERTS_DIR"
echo "   Traefik: Restarted to pick up new certificates"
echo ""
echo "🌐 You can now access your application at:"
echo "   https://\$DOMAIN"
echo ""
echo "⚠️  Note: You may need to restart your project containers for the certificates to take effect:"
echo "   docker-compose down && docker-compose up -d"
SCRIPT;

        // Write the script file
        if (file_put_contents($scriptPath, $scriptContent) === false) {
            $this->error("❌ Failed to create configure-domain.sh script");
            return;
        }
        
        // Make the script executable
        if (!chmod($scriptPath, 0755)) {
            $this->error("❌ Failed to make configure-domain.sh executable");
            return;
        }
        
        $this->info("✅ configure-domain.sh script created and made executable!");
        $this->info("🔐 Run './configure-domain.sh' to generate SSL certificates for your domain");
    }

    /**
     * Show final setup instructions with selected services table.
     */
    private function showFinalInstructions(string $projectName, array $selectedServices, ?string $customDomain = null): void
    {
        $this->line('');
        $this->line('<fg=green>══════════════════════════════════════════════════════════════</>');
        $this->line('<fg=green>                    Setup Complete!                           </>');
        $this->line('<fg=green>══════════════════════════════════════════════════════════════</>');
        
        $this->line('');
        $this->line('<fg=cyan>🚀 Next steps:</>');
        $this->line('1. 📁 Navigate to your project directory:');
        $this->line("   <fg=yellow>cd {$projectName}</>");
        
        if ($customDomain) {
            $this->line('');
            $this->line('2. 🔐 Generate SSL certificates for your domain:');
            $this->line('   <fg=yellow>./configure-domain.sh</>');
            $this->line('   <fg=gray>💡 This will add your domain to bookingkit hosts.txt, generate certificates, and update /etc/hosts</>');
            
            $this->line('');
            $this->line('3. 🐳 Start the bookingkit containers first (required for Traefik):');
            $this->line('   <fg=yellow>docker-compose -f /path/to/bookingkit/docker-compose.dev.yml up -d</>');
        } else {
            $this->line('');
            $this->line('2. 🐳 Start the bookingkit containers first:');
            $this->line('   <fg=yellow>docker-compose -f /path/to/bookingkit/docker-compose.yml up -d</>');
        }
        
        $this->line('');
        $this->line('4. 🚀 Start the Docker containers:');
        $this->line('   <fg=yellow>docker-compose up -d</>');
        
        $this->line('');
        $this->line('5. 🗄️  Run database migrations:');
        $this->line('   <fg=yellow>docker-compose exec app php artisan migrate</>');
        
        $this->line('');
        $this->line('<fg=cyan>🌐 Access your application:</>');
        
        if ($customDomain) {
            $this->line("   <fg=green>📱 Main Application:</> https://{$customDomain}");
            $this->line('   <fg=yellow>💡 Using Traefik with SSL certificate</>');
            $this->line('   <fg=gray>💡 APP_URL has been set to https://{$customDomain} in .env file</>');
        } else {
            $this->line('   <fg=green>📱 Main Application:</> http://localhost:8020');
        }
        
        // Show additional URLs based on selected services
        foreach ($selectedServices as $service) {
            if ($service === 'smtp') {
                if ($customDomain) {
                    $this->line("   <fg=green>📧 Mailpit Dashboard:</> https://mail.{$customDomain}");
                } else {
                    $this->line('   <fg=green>📧 Mailpit Dashboard:</> http://localhost:8030');
                }
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

