#include "tpAppMotionCode.hpp"

Cell3DPosition goalPosition;

TpAppMotionCode::TpAppMotionCode(SlidingCubesBlock *host):SlidingCubesBlockCode(host),module(host) {
    if (not host) return;

    addMessageEventFunc2(ELECTFIRST_MSG_ID,
                       std::bind(&TpAppMotionCode::myElectFirstFunc,this,
                       std::placeholders::_1, std::placeholders::_2));
}

void TpAppMotionCode::startup() {
    console << "start " << getId() << "\n";

    if (isLeader) { 
        setColor(RED); 
        moveToNextStep();
    }	
}

void TpAppMotionCode::myElectFirstFunc(std::shared_ptr<Message>_msg, P2PNetworkInterface*sender) {
    passBaton();
}

void TpAppMotionCode::parseUserBlockElements(TiXmlElement *config) {
    const char *attr = config->Attribute("leader");
    isLeader = (attr?Simulator::extractBoolFromString(attr):false);
    if (isLeader) {
        std::cout << getId() << " is leader!" << std::endl;
    }
}

void TpAppMotionCode::parseUserElements(TiXmlDocument *config) {
    TiXmlNode *vs = config->FirstChild("vs");
    if (!vs) return;
    
    TiXmlNode *node = vs->FirstChild("goal");
    if (!node) return;
    
    TiXmlElement *element = node->ToElement();
    const char *attr = element->Attribute("position");
    if (attr) {
        goalPosition = Simulator::extractCell3DPositionFromString(attr);
        std::cout << "goalPosition = " << goalPosition << std::endl;
    } else {
        goalPosition.set(1,5,0);
    }
}

void TpAppMotionCode::onMotionEnd() {
    console << " End of motion to " << module->position << "\n";
    if (isLeader) {
        moveToNextStep();
    }
}

void TpAppMotionCode::passBaton() {
    int myDist = abs(module->position[0] - goalPosition[0]) +
                 abs(module->position[1] - goalPosition[1]) +
                 abs(module->position[2] - goalPosition[2]);

    P2PNetworkInterface* furtherNeighbor = nullptr;
    int maxDist = myDist;

    // Check all connected interfaces to find a neighbor that is further away from the goal
    for (int i = 0; i < module->getNbInterfaces(); i++) {
        P2PNetworkInterface *p2p = module->getInterface(static_cast<SCLattice2::Direction>(i));

        if (p2p->connectedInterface) {
            BuildingBlock *neighbor = p2p->connectedInterface->hostBlock;
            int nDist = abs(neighbor->position[0] - goalPosition[0]) +
                        abs(neighbor->position[1] - goalPosition[1]) +
                        abs(neighbor->position[2] - goalPosition[2]);

            if (nDist > maxDist) {
                maxDist = nDist;
                furtherNeighbor = p2p;
            }
        }
    }

    if (furtherNeighbor) {
        sendMessage("elect", new Message(ELECTFIRST_MSG_ID), furtherNeighbor, 1000, 10);
    } else {
        console << "I am the new tail 🐍 Starting motion...\n";
        isLeader = true;
        setColor(RED);
        moveToNextStep();
    }
}

void TpAppMotionCode::moveToNextStep() {
    if (module->position == goalPosition) {
        console << "Target position reached!\n";
        return; // We did it!
    }

    Cell3DPosition activeTarget;
    if (module->position[0] == 1 && module->position[1] < goalPosition[1]) {
        activeTarget.set(1, goalPosition[1], 0);
    } else {
        activeTarget = goalPosition;
    }

    int myDist = abs(module->position[0] - activeTarget[0]) +
                 abs(module->position[1] - activeTarget[1]) +
                 abs(module->position[2] - activeTarget[2]);

    Cell3DPosition bestPos;
    int minDistance = 10000;
    bool foundMove = false;

    for (int dx = -1; dx <= 1; dx++) {
        for (int dy = -1; dy <= 1; dy++) {
            for (int dz = -1; dz <= 1; dz++) {
                if (dx == 0 && dy == 0 && dz == 0) continue;

                Cell3DPosition nextPos(module->position[0] + dx,
                                       module->position[1] + dy,
                                       module->position[2] + dz);
                if (hasPrevious && nextPos == previousPosition) {
                    continue;
                }

                if (module->canMoveTo(nextPos)) {
                    int dist = abs(nextPos[0] - activeTarget[0]) +
                               abs(nextPos[1] - activeTarget[1]) +
                               abs(nextPos[2] - activeTarget[2]);

                    // Accept moves that get closer or maintain distance
                    if (dist < minDistance && dist <= myDist) {
                        minDistance = dist;
                        bestPos = nextPos;
                        foundMove = true;
                    }
                }
            }
        }
    }

    if (foundMove) {
        previousPosition = module->position;
        hasPrevious = true;
        module->moveTo(bestPos);
    } else {
        console << "Reached end of current phase 🐍 Passing baton backward...\n";
        hasPrevious = false;
        isLeader = false;
        setColor(GREY);
        passBaton();
    }
}

void TpAppMotionCode::onGlDraw() {
    static const float thick = 0.8;
    static const float color[4] = {1.0f, 0.2f, 0.2f, 1.0f}; // Red target ring
    const Cell3DPosition& gs = lattice->gridSize;
    const Vector3D gl = lattice->gridScale;
    
    glDisable(GL_TEXTURE_2D); 
    glMaterialfv(GL_FRONT, GL_AMBIENT_AND_DIFFUSE, color);
    glPushMatrix();
    glNormal3f(0, 0, 1);
    glScalef(gl[0], gl[1], gl[2]);
    glTranslatef(goalPosition[0], goalPosition[1], goalPosition[2] - 0.49);
    glBegin(GL_QUAD_STRIP);
    for (int i = 0; i <= 36; i++) {
        double cs = 0.5 * cos(i * M_PI / 18);
        double ss = 0.5 * sin(i * M_PI / 18);
        glVertex3f(thick * cs, thick * ss, 0);
        glVertex3f(cs, ss, 0);
    }
    glEnd();
    glPopMatrix();
}