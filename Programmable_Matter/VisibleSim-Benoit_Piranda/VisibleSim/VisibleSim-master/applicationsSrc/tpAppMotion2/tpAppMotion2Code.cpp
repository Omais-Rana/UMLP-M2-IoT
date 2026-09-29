#include "tpAppMotion2Code.hpp"

Cell3DPosition goalPosition;

TpAppMotion2Code::TpAppMotion2Code(SlidingCubesBlock *host):SlidingCubesBlockCode(host),module(host) {
    if (not host) return;

    addMessageEventFunc2(ELECTFIRST_MSG_ID,
                       std::bind(&TpAppMotion2Code::myElectFirstFunc,this,
                       std::placeholders::_1, std::placeholders::_2));
}

void TpAppMotion2Code::startup() {
    console << "start " << getId() << "\n";

    if (isLeader) {
        if (updateGoal()) {
            setColor(RED);
            moveToNextStep();
        }
    }
}

BuildingBlock* TpAppMotion2Code::getBlockAt(const Cell3DPosition &pos) {
    auto &map = SlidingCubes::getWorld()->getMap();
    for (auto it = map.begin(); it != map.end(); ++it) {
        if (it->second->position == pos) return it->second;
    }
    return nullptr;
}

bool TpAppMotion2Code::updateGoal() {
    auto b = lattice->getGridUpperBounds();

    // first pass scans the grid in ascending order and permanently locks any block that happens to already be sitting inside a target cell
    // second pass scans for the nearest unfilled target cell and assigns it as the active
    for (int ix = 0; ix < b[0]; ix++) {
        for (int iy = 0; iy < b[1]; iy++) {
            for (int iz = 0; iz < b[2]; iz++) {
                Cell3DPosition pos(ix, iy, iz);

                if (target->isInTarget(pos)) {
                    BuildingBlock* bb = getBlockAt(pos);
                    if (bb) {
                        TpAppMotion2Code* code = (TpAppMotion2Code*)bb->blockCode;

                        if (!code->isLocked) {
                            code->setColor(GREEN);
                            code->isLocked = true;
                            code->isLeader = false;
                            code->hasPrevious = false;
                        }
                    }
                }
            }
        }
    }

    // Fill nearest unfilled target first instead of always chasing the farthest corner of the target region.
    for (int ix = 0; ix < b[0]; ix++) {
        for (int iy = 0; iy < b[1]; iy++) {
            for (int iz = 0; iz < b[2]; iz++) {
                Cell3DPosition pos(ix, iy, iz);

                if (target->isInTarget(pos)) {
                    if (!getBlockAt(pos)) {
                        goalPosition = pos;
                        return true;
                    }
                }
            }
        }
    }

    return false;
}

void TpAppMotion2Code::myElectFirstFunc(std::shared_ptr<Message>_msg, P2PNetworkInterface*sender) {
    passBaton();
}

void TpAppMotion2Code::parseUserBlockElements(TiXmlElement *config) {
    const char *attr = config->Attribute("leader");
    isLeader = (attr?Simulator::extractBoolFromString(attr):false);
}

void TpAppMotion2Code::parseUserElements(TiXmlDocument *config) {
}

void TpAppMotion2Code::onMotionEnd() {
    if (isLeader) {
        moveToNextStep();
    }
}

// handles the leader election
void TpAppMotion2Code::passBaton() {
    if (!updateGoal()) {
        console << "All targets filled! Simulation complete ^-^ \n";
        return;
    }

    P2PNetworkInterface* furtherNeighbor = nullptr;
    Cell3DPosition minPos = module->position;

    for (int i = 0; i < module->getNbInterfaces(); i++) {
        P2PNetworkInterface *p2p = module->getInterface(static_cast<SCLattice2::Direction>(i));
        if (p2p->connectedInterface) {
            BuildingBlock *neighbor = p2p->connectedInterface->hostBlock;

            // Allow passing through locked blocks so the message doesn't die
            if (neighbor->position[1] < minPos[1] ||
               (neighbor->position[1] == minPos[1] && neighbor->position[0] < minPos[0])) {
                minPos = neighbor->position;
                furtherNeighbor = p2p;
               }
        }
    }

    // Failsafe: If a locked block can't find a strictly lower neighbor, pass to any unlocked neighbor
    if (!furtherNeighbor && isLocked) {
        for (int i = 0; i < module->getNbInterfaces(); i++) {
            P2PNetworkInterface *p2p = module->getInterface(static_cast<SCLattice2::Direction>(i));
            if (p2p->connectedInterface) {
                TpAppMotion2Code *neighborCode = (TpAppMotion2Code*)p2p->connectedInterface->hostBlock->blockCode;
                if (!neighborCode->isLocked) {
                    furtherNeighbor = p2p;
                    break;
                }
            }
        }
    }

    if (furtherNeighbor) {
        sendMessage("elect", new Message(ELECTFIRST_MSG_ID), furtherNeighbor, 1000, 10);
    } else {
        if (isLocked) {
            console << "Error: Locked block cannot become leader!\n";
        } else {
            console << "I am the new tail 🐍 Starting motion...\n";
            isLeader = true;
            setColor(RED);
            moveToNextStep();
        }
    }
}

// calculates the block's current Manhattan distance to the goalPosition and evaluates all 26 possible adjacent 3D cells
void TpAppMotion2Code::moveToNextStep() {
    if (module->position == goalPosition) {
        console << "Target cell reached! Locking in.\n";
        setColor(GREEN);
        isLeader = false;
        hasPrevious = false;
        isLocked = true;
        passBaton(); // Elect the next module to fill the next target
        return;
    }

    // Target the goal directly
    Cell3DPosition activeTarget = goalPosition;

    int myDist = abs(module->position[0] - activeTarget[0]) +
                 abs(module->position[1] - activeTarget[1]) +
                 abs(module->position[2] - activeTarget[2]);

    Cell3DPosition bestPos;
    int minDistance = 10000;
    int bestRemainingX = 10000; // tie-break bookkeeping; see comment below
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

                    if (dist > myDist) continue;

                    int remainingX = abs(nextPos[0] - activeTarget[0]);

                    // take whichever legal move gets closest overall
                    // the one that resolves Y/Z first and leaves more X distance remaining
                    if (dist < minDistance ||
                       (dist == minDistance && remainingX > bestRemainingX)) {
                        minDistance = dist;
                        bestRemainingX = remainingX;
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

void TpAppMotion2Code::onGlDraw() {
    static const float thick=0.8;
    static const float color[4]={2.2f,0.2f,0.2f,1.0f};
    const Cell3DPosition& gs = lattice->gridSize;
    const Vector3D gl = lattice->gridScale;
    glDisable(GL_TEXTURE);
    glMaterialfv(GL_FRONT,GL_AMBIENT_AND_DIFFUSE,color);
    auto b=lattice->getGridUpperBounds();
    for (int iz=0; iz<=b[2];iz++) {
        for (int iy=0; iy<=b[1];iy++) {
            for (int ix=0; ix<=b[0];ix++) {
                if (target->isInTarget(Cell3DPosition(ix,iy,iz))) {
                    glPushMatrix();
                    glNormal3f(0,0,1);
                    glScalef(gl[0],gl[1],gl[2]);
                    glTranslatef(ix,iy,iz-0.49);
                    glBegin(GL_QUAD_STRIP);
                    for (int i=0; i<=36; i++) {
                        double cs=0.5*cos(i*M_PI/18);
                        double ss=0.5*sin(i*M_PI/18);
                        glVertex3f(thick*cs,thick*ss,0);
                        glVertex3f(cs,ss,0);
                    }
                    glEnd();
                    glPopMatrix();
                }
            }
        }
    }
}