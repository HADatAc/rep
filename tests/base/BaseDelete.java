package tests.base;

import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.TestInstance;
import org.openqa.selenium.*;
import org.openqa.selenium.chrome.ChromeDriver;
import org.openqa.selenium.chrome.ChromeOptions;
import org.openqa.selenium.interactions.Actions;
import org.openqa.selenium.support.ui.*;


import java.time.Duration;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.fail;
@TestInstance(TestInstance.Lifecycle.PER_CLASS)
public abstract class BaseDelete {

    protected WebDriver driver;
    protected WebDriverWait wait;
    protected final Map<String, Boolean> selectedRows = new HashMap<>();
    protected static final int MAX_ATTEMPTS = 10;
    protected static final int WAIT_INTERVAL_MS = 10000;
    String ip = "54.75.120.47";
    //String ip = "localhost";

    @BeforeAll
    public void setup() throws InterruptedException {
        System.setProperty("webdriver.chrome.driver", "/var/data/chromedriver/chromedriver");
        ChromeOptions options = new ChromeOptions();

        options.addArguments("--headless");
        options.addArguments("--no-sandbox");
        options.addArguments("--disable-dev-shm-usage");
        options.addArguments("--disable-gpu");
        options.setAcceptInsecureCerts(true);
        options.addArguments("--ignore-certificate-errors");

        driver = new ChromeDriver(options);
        driver.manage().window().maximize();
        wait = new WebDriverWait(driver, Duration.ofSeconds(30));

        driver.get("http://" + ip + "/user/login");

        Thread.sleep(2000);

        Actions actions = new Actions(driver);
        actions.sendKeys("thisisunsafe").perform();

        Thread.sleep(2000);
        logCurrentPageState(1000);
        wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-name")));
        wait.until(ExpectedConditions.elementToBeClickable(By.id("edit-submit")));

        driver.findElement(By.id("edit-name")).sendKeys("admin");
        driver.findElement(By.id("edit-pass")).sendKeys("admin");

        clickElementRobust(By.id("edit-submit"));

       // wait.until(ExpectedConditions.visibilityOfElementLocated(By.cssSelector("#toolbar-item-user")));
    }


    protected void deleteFile(String type, String fileName) throws InterruptedException {
        driver.get("http://" + ip + "/rep/select/mt/" + type + "/table/1/9/none");

        try {
            wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-element-table")));
        } catch (TimeoutException e) {
            fail("Table for type '" + type + "' not found.");
        }

        WebElement table = driver.findElement(By.id("edit-element-table"));
        List<WebElement> rows = table.findElements(By.tagName("tr"));

        int selectedCount = 0;
        System.out.println("Total table rows found: " + rows.size());

        selectedRows.clear(); // limpar mapa antes

        for (WebElement row : rows) {
            List<WebElement> cells = row.findElements(By.tagName("td"));
            if (cells.size() >= 3) {
                String name = cells.get(2).getText().trim();

                if (name.equals(fileName)) {
                    try {
                        // supondo que o checkbox tem id "checkbox_" + fileName, ajuste se necessário
                        String checkboxId = "checkbox_" + name;
                        checkCheckboxRobust(By.id(checkboxId));
                        selectedRows.put(name, true);
                        selectedCount++;
                        System.out.println("Selected checkbox for file: " + name);
                        break;
                    } catch (Exception e) {
                        System.out.println("Failed to select checkbox: " + e.getMessage());
                        fail("Failed to select checkbox for file: " + fileName);
                    }
                }
            }
        }

        if (selectedCount == 0) {
            System.out.println("File '" + fileName + "' not found or could not be selected.");
            return;
        }

        try {
            String buttonId = "edit-delete-selected-element";
            clickElementRobust(By.id(buttonId));

            wait.until(ExpectedConditions.alertIsPresent());
            Alert alert = driver.switchTo().alert();
            System.out.println("Delete confirmation alert: " + alert.getText());
            alert.accept();
        } catch (NoSuchElementException e) {
            fail("Delete button not found for type: " + type);
        } catch (NoAlertPresentException e) {
            fail("Expected confirmation alert not shown.");
        }

        int attempts = 0;
        boolean stillExists = true;

        while (attempts < MAX_ATTEMPTS && stillExists) {
            Thread.sleep(WAIT_INTERVAL_MS);
            driver.navigate().refresh();
            wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-element-table")));

            WebElement updatedTable = driver.findElement(By.id("edit-element-table"));
            List<WebElement> updatedRows = updatedTable.findElements(By.tagName("tr"));

            stillExists = false;

            for (WebElement row : updatedRows) {
                List<WebElement> cells = row.findElements(By.tagName("td"));
                if (cells.size() >= 3) {
                    String name = cells.get(2).getText().trim();
                    if (name.equals(fileName)) {
                        stillExists = true;
                        break;
                    }
                }
            }

            System.out.println("Attempt " + (attempts + 1) + ": File still exists? " + stillExists);

            attempts++;
        }

        assertEquals(false, stillExists, "File '" + fileName + "' was not deleted.");
    }

    protected void deleteAllFiles(String type) throws InterruptedException {
        driver.get("http://" + ip + "/rep/select/mt/" + type + "/table/1/9/none");

        try {
            wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-element-table")));
        } catch (TimeoutException e) {
            fail("Table for type '" + type + "' not found.");
        }

        WebElement table = driver.findElement(By.id("edit-element-table"));
        List<WebElement> rows = table.findElements(By.tagName("tr"));
        int selectedCount = 0;
        System.out.println("Total table rows found: " + rows.size());

        selectedRows.clear();

        for (WebElement row : rows) {
            List<WebElement> cells = row.findElements(By.tagName("td"));
            if (cells.size() >= 3) {
                String name = cells.get(2).getText().trim();

                try {
                    // Presumindo que o checkbox tem id "checkbox_" + name, ajustar se necessário
                    String checkboxId = "checkbox_" + name;
                    checkCheckboxRobust(By.id(checkboxId));
                    selectedRows.put(name, true);
                    selectedCount++;
                    System.out.println("Selected for deletion: " + name);
                } catch (Exception e) {
                    System.out.println("Failed to select checkbox for: " + name + " - " + e.getMessage());
                }
            }
        }

        if (selectedCount == 0) {
            System.out.println("No files selected for deletion.");
            return;
        }

        try {
            String buttonId = "edit-delete-selected-element";
            clickElementRobust(By.id(buttonId));

            wait.until(ExpectedConditions.alertIsPresent());
            Alert alert = driver.switchTo().alert();
            System.out.println("Delete confirmation alert: " + alert.getText());
            alert.accept();
        } catch (NoSuchElementException e) {
            fail("Delete button not found for type: " + type);
        } catch (NoAlertPresentException e) {
            fail("Expected confirmation alert not shown.");
        }

        int attempts = 0;
        boolean someStillExist = true;

        while (attempts < MAX_ATTEMPTS && someStillExist) {
            Thread.sleep(WAIT_INTERVAL_MS);
            driver.navigate().refresh();
            wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-element-table")));

            WebElement updatedTable = driver.findElement(By.id("edit-element-table"));
            List<WebElement> updatedRows = updatedTable.findElements(By.tagName("tr"));
            someStillExist = false;

            for (WebElement row : updatedRows) {
                List<WebElement> cells = row.findElements(By.tagName("td"));
                if (cells.size() >= 3) {
                    String name = cells.get(2).getText().trim();
                    if (selectedRows.containsKey(name)) {
                        someStillExist = true;
                        break;
                    }
                }
            }

            System.out.println("Attempt " + (attempts + 1) + ": Some files still exist? " + someStillExist);
            attempts++;
        }

        assertEquals(false, someStillExist, "Some files were not deleted.");
    }

    private void checkCheckboxRobust(By locator) throws InterruptedException {
        int maxAttempts = 5;
        int attempt = 0;
        while (attempt < maxAttempts) {
            attempt++;
            try {
                WebElement checkbox = wait.until(ExpectedConditions.elementToBeClickable(locator));
                if (!checkbox.isSelected()) {
                    clickElementRobust(locator);
                    Thread.sleep(300);
                }
                return; // checkbox marcado ou já estava marcado
            } catch (StaleElementReferenceException sere) {
                System.out.println("Checkbox stale, retry " + attempt);
            } catch (Exception e) {
                System.out.println("Erro ao tentar marcar checkbox na tentativa " + attempt + ": " + e.getMessage());
                if (attempt == maxAttempts) {
                    throw new RuntimeException("Falha ao marcar checkbox após " + maxAttempts + " tentativas", e);
                }
            }
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

                // Pequena pausa para garantir processamento
                Thread.sleep(300);

                System.out.println("Clique robusto finalizado na tentativa " + attempt);
                return; // sucesso

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
    public void quit() {
        if (driver != null) {
            driver.quit();
        }
    }
}
