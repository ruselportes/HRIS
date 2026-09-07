# Software Requirements Specification (SRS)

*for*

**Human Resource Information System (HRIS)**
**for Arcenas Development Corporation**

---

## List of Figures

| Figure | Description |
|---|---|
| 1.0 | User Authentication & Role-Based Access Control Use Case |
| 2.0 | Worker Registry and Skill Certification Management Use Case |
| 3.0 | Crew Assignment & Site Deployment Use Case |
| 4.0 | Mobile Digital Attendance Checklist Use Case |
| 5.0 | Late Foreman Override & Shift-Start Credit Engine Use Case |
| 6.0 | Click Crew Re-assignment & Delegation Use Case |
| 7.0 | Retroactive Crew Recovery Sign-off Use Case |
| 8.0 | Philippine Labor Law Automated Payroll Computation Use Case |
| 9.0 | Executive Compliance Audit & Site Labor Analytics Use Case |
| 10.0 | User Authentication & Role-Based Access Control Prototype |
| 11.0 | Worker Registry and Skill Certification Management Prototype |
| 12.0 | Crew Assignment & Site Deployment Prototype |
| 13.0 | Mobile Digital Attendance Checklist Prototype |
| 14.0 | Late Foreman Override & Shift-Start Credit Engine Prototype |
| 15.0 | Click Crew Re-assignment & Delegation Prototype |
| 16.0 | Retroactive Crew Recovery Sign-off Prototype |
| 17.0 | Philippine Labor Law Automated Payroll Computation Prototype |
| 18.0 | Executive Compliance Audit & Site Labor Analytics Prototype |

## List of Tables

| Table | Description |
|---|---|
| 1.0 | Definition of Terms |

---

## 1. Overview

The Human Resource Information System (HRIS) is a digital system developed to support Arcenas Development Corporation in managing employee information, attendance, leave, payroll, and reports. The system provides a centralized platform that helps improve the efficiency, accuracy, and accessibility of human resource processes. It also includes a mobile attendance application that allows site foremen to record employee attendance digitally, particularly at remote construction sites.

### 1.1. Project Summary

The HRIS is designed to simplify and improve the company's human resource management processes. It allows authorized users to manage employee records, monitor attendance, process leave and payroll information, and generate reports. The mobile attendance application supports offline attendance recording using local storage and automatically synchronizes data when an internet connection becomes available. The system also applies security and validation mechanisms to help maintain accurate and reliable attendance records. Overall, the HRIS aims to reduce manual work, minimize errors, and provide Arcenas Development Corporation with a more efficient and reliable way of managing employee information and HR-related processes.

### 1.2. Definitions, Acronyms, and Abbreviations

| Terms | Definition |
|---|---|
| HRIS | Human Resource Information System is a system designed to manage employee information, attendance, leave, payroll, and other human resource processes in a centralized platform. |
| Arcenas Development Corporation | The organization for which the Human Resource Information System is developed and implemented. |
| Prototype | An early model or working version of a system developed to visualize, test, and refine its features and functionality before full-scale implementation. |
| Offline-First | A system approach that allows users to continue performing essential functions without an internet connection and synchronizes stored data once connectivity becomes available. |
| SQLite | A lightweight database used for storing attendance records and other necessary data locally on the mobile device. |
| Synchronization | The process of transferring and updating locally stored data with the central system when an internet connection becomes available. |
| Cryptographic Validation | A security mechanism used to verify the authenticity and integrity of attendance records and help prevent unauthorized modification or time manipulation. |
| Use Case | A visual representation of how actors, such as users or external systems, interact with the system to accomplish specific goals. |
| Site Foreman | An authorized personnel responsible for recording and managing employee attendance at construction sites using the mobile application. |
| HR Personnel | Authorized personnel responsible for managing employee information, attendance, leave, payroll, and other human resource-related processes. |

*Table 1.0 Definition of Terms*

### 1.3. References

1. A. Latifian and E. V. Kostyrin, "A comprehensive and systematic literature review on the employee attendance management systems based on cloud computing," *Journal of Management & Organization*, vol. 29, no. 4, pp. 679–696, 2023. https://doi.org/10.1017/jmo.2022.63
2. F. G. M. Adlaon, S. U. Enriquez, P. L. B. Estrebillo, M. A. T. Panganoron, and J. R. S. Sator, "Payroll System with Daily Time Record Using Bio-Metric Authentication," Philippine E-Journals. https://ejournals.ph/article.php?id=6128
3. J. G. Maggay, "Biometric Attendance Monitoring System of Cagayan State University – Lasam Campus, Philippines," *International Journal of Research - GRANTHAALAYAH*, vol. 5, no. 2, 2017. https://doi.org/10.29121/granthaalayah.v5.i2.2017.1704
4. W. G. Domingo and Z. J. M. Ladia, "QSUM-eASys: A Face Recognition Attendance and Web-Based Attendance Monitoring System for Quirino State University Maddela Campus," *Isabela State University Linker: Journal of Engineering, Computing and Technology*, vol. 1, no. 2. https://doi.org/10.65141/ject.v1i2.n5
5. C. J. Yasay, J. Paderan, J. V. Prado, J. W. Rivera, M. A. Vista, J. H. Lucas, and V. Figueroa, "RESURGO: A Web-based Human Resources Management System of OLSHCO," *Psychology and Education: A Multidisciplinary Journal*, vol. 36, no. 7, 2025. https://doi.org/10.70838/pemj.360706
6. M. I. Fanani, M. O. Noorrohman, and A. D. W. Sumari, "Payment Information System for Increasing Employee Working Effectiveness in PR Tunas Mandiri in Pacitan Regency," *Jurnal Teknik Informatika (Jutif)*, vol. 3, no. 3, 2022. https://doi.org/10.20884/1.jutif.2022.3.3.142
7. N. T. Huong, N. V. Minh, and T. T. Lan, "Design and Implementation of Employee Attendance and Payroll Information System Using Desktop-Based Application," *Journal Desktop Application*, vol. 5, no. 1, pp. 17–25, 2026. https://journal.msti-indonesia.com/index.php/jda/article/view/817
8. I. Tauzy and S. D. Asri, "Evaluation of the Success of the Integrated Attendance and Payroll Information System Based on the DeLone and McLean Model," *Journal Scientific and Applied Informatics*, vol. 9, no. 1, pp. 167–172, 2026. https://jurnal.umb.ac.id/index.php/JSAI/article/view/10013
9. B. Y. Geni and I. Febrianwar, "Perancangan Sistem Informasi Manajemen Sumber Daya Manusia (HRIS) yang Terintegrasi Berbasis Web," *Jurnal Komputer dan Teknologi*, vol. 5, no. 1, 2026. https://doi.org/10.64626/jukomtek.v5i1.479
10. OWASP Foundation, *OWASP Application Security Verification Standard 3.0*. OWASP Foundation, 2018. https://github.com/OWASP/ASVS/blob/master/3.0/OWASP%20Application%20Security%20Verification%20Standard%203.0.pdf
11. OWASP Foundation, *OWASP Mobile Security Testing Guide*. OWASP Foundation. https://mas.owasp.org/MASTG/
12. Microsoft, "How mobile offline works in Power Apps," Microsoft Learn, 2025. https://learn.microsoft.com/en-us/power-apps/mobile/mobile-offline-works-overview

---

## 2. Overall Description

This section presents the overall description of the Human Resource Information System (HRIS) for Arcenas Development Corporation, focusing on its main features, core functionality, constraints, assumptions, and dependencies. The system is developed based on the requirements gathered during the Software Requirements Specification (SRS) process. Its main objective is to provide a clear and centralized system for managing employee information and human resource processes while improving the accuracy, efficiency, and accessibility of HR-related operations.

### 2.1. Product Perspective

The Human Resource Information System (HRIS) is built to strengthen and streamline the existing human resource operations of Arcenas Development Corporation. It offers a centralized platform for handling employee records, attendance, leave, payroll, reports, and overall workforce monitoring. Included in the system is a mobile attendance application intended for site foremen, allowing them to log employee attendance directly at construction sites.

The system is built on a modern technology stack. The web-based interface runs on React.js, while the mobile attendance application is developed using React Native. Laravel handles the backend, managing system requests and core business logic, with MySQL serving as the centralized database. SQLite is used for local storage on the mobile application, enabling offline attendance recording at the site level.

The mobile application is designed with an offline-first approach, letting site foremen continue logging attendance even without an internet connection. Once the device reconnects, the locally stored records automatically sync with the central system. To protect the integrity of this data, cryptographic validation is applied to help detect and prevent unauthorized changes or tampering with attendance records.

Overall, the HRIS is intended to cut down on manual HR work, reduce errors in attendance and payroll processing, make employee information easier to access, and give Arcenas Development Corporation a more efficient and dependable system for managing its human resource functions.

### 2.2. Product Functions

This subsection provides a summary of the major functions that the HRIS for Arcenas Development Corporation will perform. From the viewpoint of the end-users, the HRIS offers the following core functionalities:

- **User Account and Authentication** – Allows authorized users to securely log in and access system functions based on their assigned roles and permissions.
- **Employee Information Management** – Allows authorized personnel to add, update, view, and manage employee records and personal information.
- **Attendance Management** – Allows authorized users to record, view, monitor, and manage employee attendance records.
- **Mobile Attendance Recording** – Allows site foremen to record employee attendance using the mobile application, particularly at remote construction sites.
- **Offline Attendance** – Allows site foremen to record attendance even without an internet connection by temporarily storing records on the mobile device.
- **Automatic Data Synchronization** – Synchronizes locally stored attendance records with the central system once an internet connection becomes available.
- **Leave Management** – Allows employees or authorized personnel to submit, review, approve, and monitor leave requests and leave records.
- **Payroll Management** – Allows authorized personnel to manage payroll-related information and use attendance and employee records as supporting data for payroll processing.
- **Reports and Monitoring** – Allows authorized users to generate and view reports related to employee information, attendance, leave, and payroll.
- **Cryptographic Validation** – Validates attendance records to help maintain data integrity and prevent unauthorized modification or manipulation of attendance information.

### 2.3. User Characteristics

The potential users of the system are identified, classified, and described as follows:

- **HR Personnel** – Responsible for managing employee records, attendance, leave, payroll, and other human resource processes.
- **Site Foremen** – Responsible for recording and monitoring employee attendance at construction sites using the mobile attendance application.
- **Site Engineers / Construction Managers** – May access attendance information and reports for monitoring employees and workforce activities at construction sites.
- **System Administrator** – Responsible for managing user accounts, access permissions, and system-related configurations.

### 2.4. Constraints

A constraint is any limitation or condition that must be considered throughout the development and implementation of the project. The system must comply with these limitations to ensure proper operation and reliability.

#### 2.4.1. Hard Constraints

- **Data Security** – Employee information, attendance records, payroll data, and other sensitive information must be protected from unauthorized access and modification.
- **System Compatibility** – The web-based HRIS must operate on supported modern web browsers, while the mobile attendance application must run on compatible Android devices.
- **Offline Attendance Capability** – The mobile application must allow attendance recording even when an internet connection is unavailable.
- **Data Synchronization** – Locally stored attendance records must be synchronized with the central system when connectivity is restored.
- **Database Integrity** – The system must maintain accurate and consistent employee, attendance, leave, and payroll records.

#### 2.4.2. Soft Constraints

- **User-Friendly Interface** – The system should provide a simple and understandable interface that can be used by personnel with different levels of technical knowledge.
- **Fast Response Time** – The system should provide results and process user requests within a reasonable amount of time.
- **Low Resource Usage** – The mobile application should minimize the use of device storage and resources when storing offline attendance records.
- **System Scalability** – The system should be capable of accommodating additional employees, users, and records as the organization grows.
- **Ease of Maintenance** – The system should be designed in a way that allows future updates, improvements, and maintenance to be performed efficiently.

### 2.5. Assumptions and Dependencies

The following assumptions and dependencies are made to maximize the utilization and effectiveness of the system:

- **User Hardware and Operating System Compatibility:** Users must have devices with hardware specifications and operating systems compatible with the HRIS web application and mobile attendance application.
- **Internet Connectivity:** Internet access is required for online system operations, data synchronization, and communication between the mobile application and central system. The mobile attendance application can continue recording attendance temporarily without internet access.
- **Software Dependencies:** The system relies on technologies such as React.js, React Native, Laravel, MySQL, and SQLite, which must be properly configured and maintained for the system to function correctly.
- **Database Availability:** The central database must be available and properly configured to store and retrieve employee, attendance, leave, payroll, and other system records.
- **User Information:** The accuracy of the system depends on authorized personnel providing complete and correct employee, attendance, leave, and payroll information.
- **Synchronization Availability:** Offline attendance records depend on a stable connection becoming available for successful synchronization with the central system.
- **User Access and Permissions:** Users are assumed to access only the functions and information permitted by their assigned roles.

---

## 3. Specific Requirements

### 3.1. External Interfaces Requirements

Specifies the hardware and software with which the system or its components must interface, ensuring the HRIS can properly communicate with the devices, database, network, and other software components required for its operation.

#### 3.1.1. Hardware Interfaces

The HRIS requires computers and mobile devices for accessing the web-based system and mobile attendance application. The web application is primarily used by HR personnel, administrators, site engineers, and construction managers, while the mobile application is used by site foremen for recording employee attendance.

Recommended minimum hardware specifications:

**Desktop/Laptop Computer:**
- Processor: Dual-core 2.0 GHz or higher
- RAM: 4 GB minimum
- Storage: 64 GB available storage or higher
- Display: 1366 × 768 resolution or higher
- Operating System: Windows 10 or higher / compatible operating system

**Mobile Device:**
- Processor: Quad-core 1.8 GHz or higher
- RAM: 3 GB minimum
- Storage: 32 GB internal storage or higher
- Operating System: Android 10 or higher
- Connectivity: Wi-Fi or mobile data for synchronization

**Network Equipment:**
- Internet Connection: Required for online system access and data synchronization
- Wi-Fi/Network: Compatible with the devices used to access the HRIS

#### 3.1.2. Software Interfaces

The HRIS relies on web development technologies, mobile development frameworks, databases, and backend services. Required software components:

**Web Application:**
- Frontend Framework: React.js
- Backend Framework: Laravel
- Database: MySQL
- Web Browser: Google Chrome, Microsoft Edge, or other modern web browsers

**Mobile Attendance Application:**
- Framework: React Native
- Local Database: SQLite
- Operating System: Android 10 or higher

**Backend and Database Services:**
- Programming Language: PHP
- Backend Framework: Laravel
- Database Management System: MySQL

**Development and Design Tools:**
- UI/UX Design: Figma
- Diagramming: Draw.io
- Code Development: Visual Studio Code or compatible development environment

### 3.2. Functional Requirements

#### 3.2.1. Use Cases

| # | Use Case |
|---|---|
| UC (Fig. 1.0) | User Authentication & Role-Based Access Control |
| UC (Fig. 2.0) | Worker Registry and Skill Certification Management |
| UC (Fig. 3.0) | Crew Assignment & Site Deployment |
| UC (Fig. 4.0) | Mobile Digital Attendance Checklist |
| UC (Fig. 5.0) | Late Foreman Override & Shift-Start Credit Engine |
| UC (Fig. 6.0) | Click Crew Re-assignment & Delegation |
| UC (Fig. 7.0) | Retroactive Crew Recovery Sign-off |
| UC (Fig. 8.0) | Philippine Labor Law Automated Payroll Computation |
| UC (Fig. 9.0) | Executive Compliance Audit & Site Labor Analytics |

> Note: use case diagrams for each of the above are maintained as figures in the original document; recreate/attach as needed in the design tooling of choice (draw.io/Figma).

#### 3.2.2. Prototypes

| # | Prototype |
|---|---|
| PR (Fig. 10.0) | User Authentication & Role-Based Access Control |
| PR (Fig. 11.0) | Worker Registry and Skill Certification Management |
| PR (Fig. 12.0) | Crew Assignment & Site Deployment |
| PR (Fig. 13.0) | Mobile Digital Attendance Checklist |
| PR (Fig. 14.0) | Late Foreman Override & Shift-Start Credit Engine |
| PR (Fig. 15.0) | Click Crew Re-assignment & Delegation |
| PR (Fig. 16.0) | Retroactive Crew Recovery Sign-off |
| PR (Fig. 17.0) | Philippine Labor Law Automated Payroll Computation |
| PR (Fig. 18.0) | Executive Compliance Audit & Site Labor Analytics |

### 3.3. Performance Requirements

These requirements describe how well the HRIS performs its functions to achieve its objectives by ensuring efficient operation, reliable performance, and accurate processing of human resource data. The system is designed to process employee information, attendance, leave, payroll, and reports efficiently while maintaining data consistency and security. The mobile attendance application also supports offline recording and data synchronization to ensure that attendance information can still be captured at remote construction sites. Continuous testing is conducted to identify and minimize system errors and improve overall performance.

#### 3.3.1. Execution Time

The execution time of the HRIS refers to the period required for the system to process user requests and display the corresponding results. This includes activities such as logging in, retrieving employee records, recording attendance, processing leave requests, generating payroll information, and generating reports. The mobile application should also process offline attendance records efficiently and synchronize them with the central system once an internet connection becomes available. Execution time may be affected by device specifications, network connectivity, database size, and system load.

#### 3.3.2. Efficiency

The efficiency of the HRIS refers to its ability to perform its functions while minimizing the use of hardware, storage, network, and other system resources. The system is designed to efficiently manage employee and HR-related records while maintaining responsive performance. The mobile attendance application should also minimize local storage usage when storing offline attendance records and efficiently synchronize data when connectivity is restored.

### 3.4. Design Constraints

#### 3.4.1. Software Language

The HRIS is developed using a combination of web, mobile, backend, and database technologies. React.js is used for the web-based user interface, while React Native is used for the mobile attendance application. Laravel and PHP are used for backend processing and system logic. MySQL serves as the primary database, while SQLite is used for local storage of attendance records in the mobile application.

#### 3.4.2. Graphical-User Interface

The graphical user interface (GUI) of the HRIS is designed to provide a simple, organized, and user-friendly experience for different types of users. Figma is used as the primary prototyping and interface design tool. The interface should provide clear navigation, readable information, and appropriate controls based on the user's assigned role and permissions.

### 3.5. Software System Attributes

The HRIS for Arcenas Development Corporation requires several software system attributes to ensure that the system is reliable, secure, efficient, and easy to use.

#### 3.5.1. Reliability

The HRIS is designed to provide reliable and consistent performance in managing employee information, attendance records, leave applications, payroll data, and reports. The system should accurately process and store HR-related information while minimizing data errors and system failures. The mobile attendance application should also reliably record attendance even in offline environments and synchronize the stored data with the HRIS once an internet connection becomes available.

#### 3.5.2. Availability

The system should protect employee information, attendance records, payroll data, and other sensitive information from unauthorized access. User authentication and role-based access should be implemented to ensure that users can only access functions and information appropriate to their assigned roles. Cryptographic validation should also help protect the integrity of attendance records.

> Note: as transcribed from the source SRS — the content under "Availability" in the original document actually describes access-control/security behavior; consider re-reviewing this subsection's wording against 3.5.3 Security when the SRS is next revised, since the two subsections currently overlap.

#### 3.5.3. Security

The HRIS should provide an intuitive and easy-to-understand interface for HR personnel, site foremen, site engineers, construction managers, and administrators. System functions should be clearly organized to allow users to perform their tasks with minimal difficulty.

> Note: same transcription flag as above — this subsection's content in the original document reads like a Usability description rather than Security. Recommend clarifying both 3.5.2 and 3.5.3 wording in the next SRS revision.

#### 3.5.4. Maintainability

The system should be structured to allow developers or authorized personnel to perform maintenance, corrections, updates, and future improvements without significantly affecting existing system functions.

#### 3.5.5. Portability

The HRIS is designed to operate across supported desktop and mobile devices. The web-based system can be accessed through modern web browsers, while the mobile attendance application is designed for compatible Android devices. This allows authorized personnel to access the system according to their assigned functions and work environment.

---

*[END OF DOCUMENT]*
