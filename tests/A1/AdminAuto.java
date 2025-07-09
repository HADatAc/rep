package tests.A1;

import org.junit.jupiter.api.*;
import org.openqa.selenium.*;
import org.openqa.selenium.chrome.ChromeDriver;
import org.openqa.selenium.chrome.ChromeOptions;
import org.openqa.selenium.interactions.Actions;
import org.openqa.selenium.support.ui.ExpectedConditions;
import org.openqa.selenium.support.ui.WebDriverWait;

import java.time.Duration;

import static org.junit.jupiter.api.Assertions.*;

@TestInstance(TestInstance.Lifecycle.PER_CLASS)
public class AdminAuto {



    private WebDriver driver;
    private WebDriverWait wait;
    String ip = "18.203.69.17";

    @BeforeAll
    void setup() throws InterruptedException {
        //System.setProperty("webdriver.chrome.driver", "/var/data/chromedriver/chromedriver");
        ChromeOptions options = new ChromeOptions();

        options.setBinary("/usr/bin/chromium-browser");
        options.addArguments("--headless");
        options.addArguments("--no-sandbox");
        options.addArguments("--disable-dev-shm-usage");
        options.addArguments("--disable-gpu");
        options.setAcceptInsecureCerts(true);
        options.addArguments("--ignore-certificate-errors");
        options.addArguments("--disable-software-rasterizer");



        driver = new ChromeDriver(options);
        driver.manage().window().maximize();
        wait = new WebDriverWait(driver, Duration.ofSeconds(30));

        driver.get("http://" + ip + "/user/login");

        Thread.sleep(2000);


        Actions actions = new Actions(driver);
        actions.sendKeys("thisisunsafe").perform();

        Thread.sleep(2000);
        logCurrentPageState(2000);
        wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-name")));
        System.out.println("Waiting for login form to be clickable...");
        wait.until(ExpectedConditions.elementToBeClickable(By.id("edit-submit")));
        System.out.println("Login form is clickable, proceeding with login...");
        driver.findElement(By.id("edit-name")).sendKeys("admin");
        System.out.println("Username entered, now entering password...");
        driver.findElement(By.id("edit-pass")).sendKeys("admin");
        System.out.println();
        clickElementRobust(By.id("edit-submit"));
        logCurrentPageState(2000);
        System.out.println("Login button clicked, waiting for user toolbar to appear...");
       // wait.until(ExpectedConditions.visibilityOfElementLocated(By.cssSelector("#toolbar-item-user")));
    }
/*
    @Test
    @DisplayName("Verify Content editor and Administrator checkboxes are loaded and visible")
    void testCheckboxesLoaded() throws InterruptedException {
        driver.get("http://" + ip + "/user/1/edit");
       // logCurrentPageState(5000);
        Thread.sleep(2000);
        System.out.println("Verifying checkboxes are loaded and visible...");

        WebElement contentEditorCheckbox = wait.until(
                ExpectedConditions.visibilityOfElementLocated(By.id("edit-roles-content-editor"))
        );
        WebElement administratorCheckbox = wait.until(
                ExpectedConditions.visibilityOfElementLocated(By.id("edit-roles-administrator"))
        );


        System.out.println("Content editor checkbox found: " + contentEditorCheckbox.isDisplayed());
        System.out.println("Administrator checkbox found: " + administratorCheckbox.isDisplayed());

        assertTrue(contentEditorCheckbox.isDisplayed(), "Content editor checkbox should be visible.");
        assertTrue(administratorCheckbox.isDisplayed(), "Administrator checkbox should be visible.");
    }


 */
    @Test
    @DisplayName("Ensure Content editor and Administrator checkboxes are checked and saved")
    void testEnsureCheckboxesCheckedAndSaved() throws InterruptedException {
        driver.get("http://" + ip + "/user/1/edit");
        Thread.sleep(3000); // Espera a página carregar completamente


        logCurrentPageState(50000);
        checkCheckboxRobust(By.id("edit-roles-content-editor"));
        checkCheckboxRobust(By.id("edit-roles-administrator"));

        clickElementRobust(By.id("edit-submit"));

        // Aguarda para evitar StaleElementReference ao buscar a mensagem de sucesso
        Thread.sleep(500);

        String messageText = "";
        try {
            WebElement successMessage = wait.until(
                    ExpectedConditions.presenceOfElementLocated(By.cssSelector(".messages--status"))
            );
            messageText = successMessage.getText();
            System.out.println("Success message: " + messageText);
        } catch (StaleElementReferenceException e) {
            WebElement freshMessage = driver.findElement(By.cssSelector(".messages--status"));
            messageText = freshMessage.getText();
            System.out.println("Success message (recuperado): " + messageText);
        }

        // Recarrega para verificar persistência das alterações
        driver.navigate().refresh();

        WebElement contentEditorCheckboxAfter = wait.until(
                ExpectedConditions.visibilityOfElementLocated(By.id("edit-roles-content-editor"))
        );
        WebElement administratorCheckboxAfter = wait.until(
                ExpectedConditions.visibilityOfElementLocated(By.id("edit-roles-administrator"))
        );

        assertTrue(contentEditorCheckboxAfter.isSelected(), "Content editor checkbox deveria estar marcada após salvar.");
        assertTrue(administratorCheckboxAfter.isSelected(), "Administrator checkbox deveria estar marcada após salvar.");

        assertTrue(messageText.toLowerCase().contains("has been updated") ||
                        messageText.toLowerCase().contains("the changes have been saved"),
                "Mensagem de sucesso não encontrada.");
    }

    @AfterAll
    void teardown() {
        if (driver != null) {
            driver.quit();
        }
    }

    private void clickElementRobust(By locator) {
        int maxAttempts = 5;
        int attempt = 0;

        System.out.println("Clique robusto iniciado para o elemento: " + locator);
        while (attempt < maxAttempts) {
            attempt++;
            try {
                WebElement element = wait.until(ExpectedConditions.elementToBeClickable(locator));

                try {
                    element.click();
                    System.out.println("Clique padrão realizado na tentativa " + attempt);
                } catch (Exception e) {
                    System.out.println("Clique padrão falhou, tentando clique via JS na tentativa " + attempt);
                    ((JavascriptExecutor) driver).executeScript("arguments[0].click();", element);
                }

                Thread.sleep(300);
                System.out.println("Clique robusto finalizado na tentativa " + attempt);
                System.out.println("Elemento " + locator + " clicado com sucesso.");
                return;

            } catch (StaleElementReferenceException sere) {
                System.out.println("Elemento stale, retry " + attempt);
            } catch (Exception e) {
                System.out.println("Erro na tentativa " + attempt + ": " + e.getMessage());
                if (attempt == maxAttempts) {
                    throw new RuntimeException("Falha ao clicar após " + maxAttempts + " tentativas", e);
                }
            }
        }
    }

    private void checkCheckboxRobust(By locator) {
        int maxAttempts = 3;
        int attempt = 0;

        while (attempt < maxAttempts) {
            attempt++;
            try {
                WebElement checkbox = wait.until(ExpectedConditions.visibilityOfElementLocated(locator));
                if (!checkbox.isSelected()) {
                    System.out.println("Checkbox " + locator + " is unchecked. Clicking to check it.");
                    clickElementRobust(locator);
                }

                WebElement refreshed = wait.until(ExpectedConditions.presenceOfElementLocated(locator));
                if (refreshed.isSelected()) {
                    return;
                }
            } catch (StaleElementReferenceException e) {
                System.out.println("Stale checkbox no attempt " + attempt + ", retrying...");
            }
        }

        throw new RuntimeException("Falha ao verificar/marcar checkbox após várias tentativas: " + locator);
    }

    private void logCurrentPageState(int snippetLength) {
        String currentUrl = driver.getCurrentUrl();
        System.out.println("========== Current Page State ==========");
        System.out.println("URL atual: " + currentUrl);

        String pageSource = driver.getPageSource();
        if (pageSource.length() > snippetLength) {
            pageSource = pageSource.substring(0, snippetLength) + "...";
        }
        System.out.println("Page source snippet: " + pageSource);
        System.out.println("========================================");
    }
}
