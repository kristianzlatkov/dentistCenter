<?php

namespace App\Controller;

use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use \Symfony\Component\HttpFoundation\Request;
use App\Entity\Appointments;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class CalendarController extends AbstractController
{
    #[Route('/calendar', name: 'app_calendar', methods: 'GET')]
    public function index(EntityManagerInterface $entityManager, SerializerInterface $serializer): Response
    {
        $appointments = $entityManager->getRepository(Appointments::class)->findAll();
        $appointmentsJson = $serializer->serialize($appointments, 'json');

        return $this->render('calendar/index.html.twig', [
            'appointments' => $appointmentsJson,
        ]);
    }

    /**
     * @throws Exception
     */
    #[Route('/appointments', name: 'appointments', methods: 'POST')]
    public function saveAppointment(
        Request $request,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator
    ): JsonResponse
    {
        $requestData = $request->request->all();

        // Convert start and end times to DateTime objects
        $start = new \DateTime($requestData['start']);
        $end = new \DateTime($requestData['end']);

        // Initialize the query builder
        $queryBuilder = $entityManager->getRepository(Appointments::class)
            ->createQueryBuilder('a');

        $action = 'create';
        // Check if id is provided in the request data
        if (!empty($requestData['id'])) {
            $action = 'update';
            $queryBuilder->andWhere('a.id != :id')
                ->setParameter('id', $requestData['id']);
        }

        // Add conditions for overlapping appointments
        $existingAppointments = $queryBuilder
            ->andWhere(':start < a.end')
            ->andWhere(':end > a.start')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getResult();

        if (!empty($existingAppointments)) {
            // Return a response indicating that there is a conflict
            return new JsonResponse(['error' => 'There is already an appointment in this time range.'], 400);
        }

        $errors = $validator->validate($existingAppointments);
        if (count($errors) > 0) {
            return new JsonResponse(['error' => "Error log: $errors."], 400);
        }

        if ($action === 'create') {
            $appointment = new Appointments();
        } else {
            $appointment = $entityManager->getRepository(Appointments::class)->find($requestData['id']);
        }

        $appointment->setTitle($requestData['title']);
        $appointment->setStart($start);
        $appointment->setEnd($end);
        $appointment->setEmailAddress($requestData['email_address']);
        $appointment->setPhoneNumber($requestData['phone_number']);

        // Persist and flush the new appointment
        $entityManager->persist($appointment);
        $entityManager->flush();

        // Return a success response
        return new JsonResponse('Appointment saved successfully', 200);
    }

    #[Route('/appointments/{id}', methods: 'GET')]
    public function showByPk($id , Appointments $appointment, EntityManagerInterface $entityManager): JsonResponse
    {
        $appointment = $entityManager->getRepository(Appointments::class)->find($id);
        $appointmentData = [
            'id' => $appointment->getId(),
            'title' => $appointment->getTitle(),
            'start' => $appointment->getStart()->format('Y-m-d H:i:s'),
            'end' => $appointment->getEnd()->format('Y-m-d H:i:s'),
            'email_address' => $appointment->getEmailAddress(),
            'phone_number' => $appointment->getPhoneNumber(),
        ];

        // Return the appointment details as JSON response
        return new JsonResponse($appointmentData);
    }

    #[Route('/appointments/{id}', methods: 'DELETE')]
    public function deleteAppointment($id , Appointments $appointment, EntityManagerInterface $entityManager): JsonResponse
    {
        $appointment = $entityManager->getRepository(Appointments::class)->find($id);
        $entityManager->remove($appointment);
        $entityManager->flush();

        return new JsonResponse('Successfully deleted appointment.');
    }
}